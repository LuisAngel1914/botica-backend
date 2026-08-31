<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Producto;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function responder(Request $request)
    {
        $mensaje = trim($request->input('mensaje', ''));

        if (empty($mensaje)) {
            return response()->json([
                'respuesta' => 'Por favor escribe una consulta o pregunta sobre algún producto.'
            ], 200);
        }

        $mensajeMinuscula = strtolower($mensaje);
        $respuestaGenericaRechazo = '🤖 Lo siento, solo puedo responder preguntas relacionadas con la botica, medicamentos, productos en stock, precios y consultas farmacéuticas.';

        // 1. Detección de intenciones para solicitar el catálogo o productos disponibles
        $intencionesGeneral = [
            'productos disponibles', 'productos hay', 'que productos', 'qué productos', 
            'lista de productos', 'lista', 'catalogo', 'catálogo', 'inventario', 'stock general'
        ];

        $esConsultaGeneral = false;
        foreach ($intencionesGeneral as $intencion) {
            if (str_contains($mensajeMinuscula, $intencion)) {
                $esConsultaGeneral = true;
                break;
            }
        }

        // Si es una solicitud general de stock/catálogo
        if ($esConsultaGeneral) {
            $productos = Producto::take(10)->get();
            if ($productos->count() > 0) {
                $txt = "🤖 **Productos disponibles en el sistema:**\n\n";
                foreach ($productos as $prod) {
                    $stockVal = $prod->stock_actual ?? $prod->stock_total ?? $prod->stock ?? 0;
                    $precioVal = $prod->precio ?? $prod->precio_venta ?? 0;
                    $precioFormateado = number_format((float)$precioVal, 2, '.', '');

                    $txt .= "• **{$prod->nombre}** | Stock: {$stockVal} unidades | Precio: S/ {$precioFormateado}\n";
                }
                return response()->json(['respuesta' => $txt], 200);
            }
        }

        // 2. Extraer palabras clave para búsquedas específicas
        $palabrasOmitir = [
            'hay', 'productos', 'producto', 'disponibles', 'disponible', 'de', 'del', 
            'la', 'el', 'los', 'las', 'un', 'una', 'cuanto', 'cuánto', 'cuesta', 'precio', 
            'tienen', 'tiene', 'sobre', 'que', 'qué', 'en', 'para', 'saber', 'si', 'busco', 
            'con', 'por', 'cuales', 'cuáles', 'cuantos', 'cuántos', 'existen', 'años', 'tienes', '?'
        ];

        $tokens = explode(' ', preg_replace('/[^\w\s]/u', '', $mensajeMinuscula));
        $palabrasClave = array_filter($tokens, function($token) use ($palabrasOmitir) {
            return !in_array($token, $palabrasOmitir) && strlen($token) > 2;
        });

        // 3. Buscar coincidencias en la base de datos
        $productosEncontrados = collect();
        if (!empty($palabrasClave)) {
            $query = Producto::query();
            $query->where(function($q) use ($palabrasClave) {
                foreach ($palabrasClave as $palabra) {
                    $q->orWhereRaw('LOWER(nombre) LIKE ?', ["%{$palabra}%"])
                      ->orWhere('codigo_barras', 'LIKE', "%{$palabra}%");
                }
            });
            $productosEncontrados = $query->take(5)->get();
        }

        // 4. Determinar si la pregunta pertenece al dominio de la botica/farmacia
        $terminosFarmaceuticos = [
            'medicamento', 'medicamentos', 'producto', 'productos', 'precio', 'precios', 
            'cuesta', 'costo', 'stock', 'inventario', 'botica', 'farmacia', 'pastilla', 
            'pastillas', 'jarabe', 'ampolla', 'tableta', 'tabletas', 'dosis', 'tratamiento', 
            'receta', 'equivalente', 'alternativa', 'sintoma', 'síntoma', 'dolor', 'vender', 
            'venta', 'comprar', 'disponible', 'disponibles', 'habrá', 'tienen', 'hay'
        ];

        $esConsultaFarmaceutica = false;
        foreach ($terminosFarmaceuticos as $termino) {
            if (str_contains($mensajeMinuscula, $termino)) {
                $esConsultaFarmaceutica = true;
                break;
            }
        }

        // Si NO hay productos encontrados y NO es una consulta farmacéutica -> Rechazar
        if ($productosEncontrados->count() === 0 && !$esConsultaFarmaceutica) {
            return response()->json([
                'respuesta' => $respuestaGenericaRechazo
            ], 200);
        }

        // 5. Intento con Gemini IA (Si existe API KEY)
        $apiKey = env('GEMINI_API_KEY');
        if ($apiKey) {
            try {
                $catalogo = Producto::select('nombre', 'precio', 'precio_venta', 'stock', 'stock_actual')->take(20)->get()->toJson();
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

                $response = Http::withoutVerifying()->timeout(8)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Eres un asistente farmacéutico exclusivo de esta botica. Responde ÚNICAMENTE sobre medicamentos, salud, dosis o productos. Si la pregunta es ajena a la botica o medicina, responde exactamente: '{$respuestaGenericaRechazo}'. Productos en sistema: {$catalogo}. Consulta: {$mensaje}"]
                            ]
                        ]
                    ]
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $textoIa = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if ($textoIa) {
                        return response()->json(['respuesta' => $textoIa], 200);
                    }
                }
            } catch (\Throwable $e) {
                // Fallback automático al motor local
            }
        }

        // 6. Generación de respuesta con productos encontrados
        if ($productosEncontrados->count() > 0) {
            $txt = "🤖 **Productos encontrados en el sistema:**\n\n";
            foreach ($productosEncontrados as $prod) {
                $stockVal = $prod->stock_actual ?? $prod->stock_total ?? $prod->stock ?? 0;
                $precioVal = $prod->precio ?? $prod->precio_venta ?? 0;
                $precioFormateado = number_format((float)$precioVal, 2, '.', '');

                $txt .= "• **{$prod->nombre}** | Stock: {$stockVal} unidades | Precio: S/ {$precioFormateado}\n";
            }
            return response()->json(['respuesta' => $txt], 200);
        }

        // 7. Respuesta si la consulta era sobre farmacia/botica pero el producto no existe en el catálogo
        return response()->json([
            'respuesta' => "🤖 No encontré ningún producto registrado en el sistema que coincida con tu búsqueda. Intenta consultando por el nombre del medicamento o su principio activo."
        ], 200);
    }
}