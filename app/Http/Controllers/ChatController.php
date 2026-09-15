<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    private const MEDICAL_TERMS = [
        'dosis', 'dosificacion', 'dosificación', 'tomar', 'tratamiento', 'diagnostico',
        'diagnóstico', 'sintoma', 'síntoma', 'embarazo', 'interaccion', 'interacción',
        'contraindicacion', 'contraindicación', 'recetar', 'cura',
    ];

    private const QUERY_STOP_WORDS = [
        'hay', 'productos', 'producto', 'disponibles', 'disponible', 'de', 'del', 'la',
        'el', 'los', 'las', 'un', 'una', 'cuanto', 'cuánto', 'cuesta', 'precio', 'precios',
        'tienen', 'tiene', 'sobre', 'que', 'qué', 'en', 'para', 'saber', 'si', 'busco',
        'con', 'por', 'cuales', 'cuáles', 'cuantos', 'cuántos', 'existen', 'tienes',
        'mostrar', 'ver', 'catalogo', 'catálogo', 'inventario', 'stock', 'lista',
    ];

    public function responder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mensaje' => 'required|string|max:500',
        ]);

        $mensaje = trim($data['mensaje']);
        $mensajeNormalizado = $this->normalizar($mensaje);

        if ($this->containsMedicalAdviceRequest($mensajeNormalizado)) {
            return response()->json([
                'code' => 'MEDICAL_ADVICE_UNAVAILABLE',
                'respuesta' => 'Por seguridad, este asistente no brinda dosis, diagnósticos ni recomendaciones terapéuticas. Consulta al químico farmacéutico o a un profesional de salud. Sí puedo ayudarte con disponibilidad, precio, presentación, principio activo y condición de venta.',
                'productos' => [],
            ]);
        }

        $palabrasClave = $this->extractKeywords($mensajeNormalizado);
        $productos = $this->buscarProductosDisponibles($palabrasClave);

        if ($productos->isEmpty() && !$this->isCatalogRequest($mensajeNormalizado)) {
            return response()->json([
                'code' => 'OUT_OF_SCOPE',
                'respuesta' => 'Puedo ayudarte únicamente con el catálogo de la botica: productos, stock vigente, precios, presentación, principio activo y condición de venta.',
                'productos' => [],
            ]);
        }

        if ($productos->isEmpty()) {
            return response()->json([
                'code' => 'NO_CATALOG_MATCH',
                'respuesta' => 'No encontré productos vigentes que coincidan con tu consulta. Prueba con el nombre comercial, principio activo o código de barras.',
                'productos' => [],
            ]);
        }

        $productosNormalizados = $productos->map(fn (Producto $producto) => [
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'principio_activo' => $producto->principio_activo,
            'presentacion' => $producto->presentacion,
            'condicion_venta' => $producto->condicion_venta ?? ($producto->requiere_receta ? 'con_receta' : 'libre'),
            'precio_venta' => (float) $producto->precio_venta,
            'stock_disponible' => (int) $producto->stock_disponible,
            'imagen_url' => $producto->imagen_url,
        ])->values();

        return response()->json([
            'code' => 'CATALOG_RESULTS',
            'respuesta' => $this->respuestaCatalogo($productosNormalizados),
            'productos' => $productosNormalizados,
        ]);
    }

    private function buscarProductosDisponibles(array $palabrasClave): Collection
    {
        $hoy = now()->toDateString();

        return Producto::query()
            ->whereHas('lotes', function ($query) use ($hoy) {
                $query->where('stock', '>', 0)
                    ->whereDate('fecha_vencimiento', '>=', $hoy);
            })
            ->withSum(['lotes as stock_disponible' => function ($query) use ($hoy) {
                $query->where('stock', '>', 0)
                    ->whereDate('fecha_vencimiento', '>=', $hoy);
            }], 'stock')
            ->when($palabrasClave !== [], function ($query) use ($palabrasClave) {
                $query->where(function ($matches) use ($palabrasClave) {
                    foreach ($palabrasClave as $palabra) {
                        $matches->orWhereRaw('LOWER(nombre) LIKE ?', ['%' . $palabra . '%'])
                            ->orWhereRaw('LOWER(principio_activo) LIKE ?', ['%' . $palabra . '%'])
                            ->orWhere('codigo_barras', 'LIKE', '%' . $palabra . '%');
                    }
                });
            })
            ->orderBy('nombre')
            ->limit(5)
            ->get();
    }

    private function respuestaCatalogo(Collection $productos): string
    {
        $total = $productos->count();

        return $total === 1
            ? 'Encontré un producto con stock vigente. Revisa su ficha para confirmar presentación, precio y condición de venta.'
            : "Encontré {$total} productos con stock vigente. Revisa las fichas para comparar presentación, precio y condición de venta.";
    }

    private function containsMedicalAdviceRequest(string $mensaje): bool
    {
        foreach (self::MEDICAL_TERMS as $termino) {
            if (str_contains($mensaje, $termino)) {
                return true;
            }
        }

        return false;
    }

    private function isCatalogRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'producto')
            || str_contains($mensaje, 'catalogo')
            || str_contains($mensaje, 'inventario')
            || str_contains($mensaje, 'stock')
            || str_contains($mensaje, 'disponible')
            || str_contains($mensaje, 'precio');
    }

    private function extractKeywords(string $mensaje): array
    {
        $tokens = array_filter(explode(' ', preg_replace('/[^\pL\pN\s]/u', '', $mensaje)));

        return array_values(array_filter($tokens, fn (string $token) => strlen($token) > 2 && !in_array($token, self::QUERY_STOP_WORDS, true)));
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim($texto));
    }
}
