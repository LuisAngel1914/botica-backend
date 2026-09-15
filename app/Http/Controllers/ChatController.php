<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ChatController extends Controller
{
    private const MEDICAL_TERMS = [
        'dosis', 'dosificacion', 'dosificación', 'tomar', 'tratamiento', 'diagnostico',
        'diagnóstico', 'sintoma', 'síntoma', 'embarazo', 'interaccion', 'interacción',
        'contraindicacion', 'contraindicación', 'recetar', 'cura',
    ];

    private const ACTION_TERMS = [
        'anular', 'cancelar venta', 'corregir', 'modificar', 'editar', 'eliminar',
        'crear usuario', 'registrar venta', 'abrir caja', 'cerrar caja', 'dar de baja',
    ];

    private const QUERY_STOP_WORDS = [
        'hay', 'productos', 'producto', 'disponibles', 'disponible', 'de', 'del', 'la',
        'el', 'los', 'las', 'un', 'una', 'cuanto', 'cuánto', 'cuesta', 'precio', 'precios',
        'tienen', 'tiene', 'sobre', 'que', 'qué', 'en', 'para', 'saber', 'si', 'busco',
        'con', 'por', 'cuales', 'cuáles', 'cuantos', 'cuántos', 'existen', 'tienes',
        'mostrar', 'ver', 'catalogo', 'catálogo', 'inventario', 'stock', 'lista',
        'quiero', 'necesito', 'dame', 'informacion', 'información', 'consulta',
    ];

    public function responder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mensaje' => 'required|string|max:500',
        ]);

        $mensaje = trim($data['mensaje']);
        $mensajeNormalizado = $this->normalizar($mensaje);
        $usuario = $request->user();

        if ($this->containsMedicalAdviceRequest($mensajeNormalizado)) {
            return $this->response('MEDICAL_ADVICE_UNAVAILABLE', 'Por seguridad, este asistente no brinda dosis, diagnósticos ni recomendaciones terapéuticas. Consulta al químico farmacéutico o a un profesional de salud. Sí puedo ayudarte con la operación de Botica L y L.');
        }

        if ($this->containsActionRequest($mensajeNormalizado)) {
            return $this->response('ACTION_REQUIRES_MODULE', 'Para proteger la trazabilidad, no ejecuto anulaciones, correcciones, cierres ni cambios desde el chat. Realiza la operación desde el módulo correspondiente, donde el sistema solicitará las validaciones y el motivo.');
        }

        if ($this->isUserRequest($mensajeNormalizado)) {
            return $this->answerUsers($usuario);
        }

        if ($this->isReportRequest($mensajeNormalizado)) {
            return $this->answerReports($usuario);
        }

        if ($this->isCashRequest($mensajeNormalizado)) {
            return $this->answerCashStatus();
        }

        if ($this->isSaleRequest($mensajeNormalizado)) {
            return $this->answerSales($usuario);
        }

        if ($this->isClientRequest($mensajeNormalizado)) {
            return $this->answerClient($mensaje, $usuario);
        }

        if ($this->isInventoryAlertRequest($mensajeNormalizado)) {
            return $this->answerInventoryAlerts($usuario);
        }

        if ($this->isCatalogRequest($mensajeNormalizado)) {
            return $this->answerCatalog($mensajeNormalizado);
        }

        return $this->response('OUT_OF_SCOPE', 'Solo puedo ayudarte con la operación de Botica L y L: productos, inventario, lotes, caja, ventas, clientes, reportes y usuarios autorizados.');
    }

    private function answerCashStatus(): JsonResponse
    {
        $caja = Caja::query()->with('usuario:id,name')->where('estado', 'abierta')->latest('fecha_apertura')->first();

        if (!$caja) {
            return $this->response('CASH_STATUS', 'No hay una caja abierta actualmente.', ['estado' => 'cerrada']);
        }

        $fechaApertura = $caja->fecha_apertura ?? $caja->created_at;
        $ventasEfectivo = (float) Venta::query()
            ->where('created_at', '>=', $fechaApertura)
            ->where('estado', 'completada')
            ->where('metodo_pago', 'Efectivo')
            ->sum('total');

        $esperado = (float) $caja->monto_inicial + $ventasEfectivo;

        return $this->response(
            'CASH_STATUS',
            'La caja está abierta a cargo de ' . ($caja->usuario?->name ?? 'un operador') . '. Monto inicial: S/ ' . number_format((float) $caja->monto_inicial, 2) . '. Efectivo registrado: S/ ' . number_format($ventasEfectivo, 2) . '. Monto esperado: S/ ' . number_format($esperado, 2) . '.',
            [
                'estado' => 'abierta',
                'caja_id' => $caja->id,
                'monto_inicial' => (float) $caja->monto_inicial,
                'ventas_efectivo' => $ventasEfectivo,
                'monto_esperado' => $esperado,
            ]
        );
    }

    private function answerSales(User $usuario): JsonResponse
    {
        $ventas = Venta::query()
            ->whereDate('created_at', today())
            ->where('estado', 'completada')
            ->when(!$this->isAdmin($usuario), fn ($query) => $query->where('usuario_id', $usuario->id));

        $cantidad = (int) (clone $ventas)->count();
        $total = (float) (clone $ventas)->sum('total');
        $alcance = $this->isAdmin($usuario) ? 'de toda la botica' : 'registradas por ti';

        return $this->response(
            'SALES_TODAY',
            "Hoy hay {$cantidad} ventas completadas {$alcance}, por S/ " . number_format($total, 2) . '.',
            ['fecha' => today()->toDateString(), 'cantidad_ventas' => $cantidad, 'total_ventas' => $total, 'alcance' => $this->isAdmin($usuario) ? 'global' : 'propio']
        );
    }

    private function answerReports(User $usuario): JsonResponse
    {
        if (!$this->isAdmin($usuario)) {
            return $this->forbidden('reportes');
        }

        $ventas = Venta::query()->whereDate('created_at', today())->where('estado', 'completada');
        $cantidad = (int) (clone $ventas)->count();
        $total = (float) (clone $ventas)->sum('total');
        $productosCriticos = (int) Producto::query()->whereColumn('stock_actual', '<=', 'stock_minimo')->count();

        return $this->response(
            'REPORT_SUMMARY',
            'Resumen de hoy: ' . $cantidad . ' ventas completadas por S/ ' . number_format($total, 2) . ' y ' . $productosCriticos . ' productos con stock crítico.',
            ['fecha' => today()->toDateString(), 'ventas_completadas' => $cantidad, 'total_ventas' => $total, 'productos_stock_critico' => $productosCriticos]
        );
    }

    private function answerInventoryAlerts(User $usuario): JsonResponse
    {
        if (!$this->isAdmin($usuario)) {
            return $this->forbidden('alertas de inventario y lotes');
        }

        $hoy = today();
        $criticos = (int) Producto::query()->whereColumn('stock_actual', '<=', 'stock_minimo')->count();
        $porVencer = (int) Lote::query()
            ->where('stock', '>', 0)
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->whereDate('fecha_vencimiento', '<=', now()->addDays(60))
            ->count();
        $vencidos = (int) Lote::query()->where('stock', '>', 0)->whereDate('fecha_vencimiento', '<', $hoy)->count();

        return $this->response(
            'INVENTORY_ALERTS',
            "Inventario: {$criticos} productos con stock crítico, {$porVencer} lotes por vencer en 60 días y {$vencidos} lotes vencidos con stock.",
            ['stock_critico' => $criticos, 'lotes_por_vencer' => $porVencer, 'lotes_vencidos' => $vencidos]
        );
    }

    private function answerClient(string $mensaje, User $usuario): JsonResponse
    {
        if (!preg_match('/\\b\\d{8,11}\\b/', $mensaje, $coincidencia)) {
            return $this->response('CLIENT_DOCUMENT_REQUIRED', 'Para consultar un cliente, escribe su DNI o RUC completo. Por privacidad, no muestro clientes por nombre desde el chat.');
        }

        $cliente = Cliente::query()->where('numero_documento', $coincidencia[0])->first();

        if (!$cliente) {
            return $this->response('CLIENT_NOT_FOUND', 'No encontré un cliente registrado con ese documento.');
        }

        $data = [
            'id' => $cliente->id,
            'tipo_documento' => $cliente->tipo_documento,
            'numero_documento' => $cliente->numero_documento,
            'nombre_razon_social' => $cliente->nombre_razon_social,
            'estado' => $cliente->estado,
        ];

        if ($this->isAdmin($usuario)) {
            $data['ventas_registradas'] = (int) $cliente->ventas()->count();
        }

        return $this->response('CLIENT_FOUND', 'Encontré al cliente ' . $cliente->nombre_razon_social . '.', $data);
    }

    private function answerUsers(User $usuario): JsonResponse
    {
        if (!$this->isAdmin($usuario)) {
            return $this->forbidden('usuarios');
        }

        $total = (int) User::query()->count();
        $activos = (int) User::query()->where('activo', true)->count();

        return $this->response('USERS_SUMMARY', "Hay {$activos} usuarios activos de {$total} registrados.", ['usuarios_activos' => $activos, 'usuarios_total' => $total]);
    }

    private function answerCatalog(string $mensaje): JsonResponse
    {
        $productos = $this->buscarProductosDisponibles($this->extractKeywords($mensaje));

        if ($productos->isEmpty()) {
            return $this->response('NO_CATALOG_MATCH', 'No encontré productos vigentes que coincidan con tu consulta. Prueba con el nombre comercial, principio activo o código de barras.');
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

        $total = $productosNormalizados->count();

        return $this->response(
            'CATALOG_RESULTS',
            $total === 1
                ? 'Encontré un producto con stock vigente. Revisa su ficha para confirmar presentación, precio y condición de venta.'
                : "Encontré {$total} productos con stock vigente. Revisa las fichas para comparar presentación, precio y condición de venta.",
            [],
            $productosNormalizados
        );
    }

    private function buscarProductosDisponibles(array $palabrasClave): Collection
    {
        $hoy = today()->toDateString();

        return Producto::query()
            ->whereHas('lotes', function ($query) use ($hoy) {
                $query->where('stock', '>', 0)->whereDate('fecha_vencimiento', '>=', $hoy);
            })
            ->withSum(['lotes as stock_disponible' => function ($query) use ($hoy) {
                $query->where('stock', '>', 0)->whereDate('fecha_vencimiento', '>=', $hoy);
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

    private function response(string $code, string $respuesta, array $data = [], Collection|array $productos = []): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'respuesta' => $respuesta,
            'data' => $data,
            'productos' => $productos,
        ]);
    }

    private function forbidden(string $modulo): JsonResponse
    {
        return $this->response('FORBIDDEN_MODULE', "No tienes permiso para consultar {$modulo} desde el asistente. Solicita acceso a un administrador si lo necesitas.");
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

    private function containsActionRequest(string $mensaje): bool
    {
        foreach (self::ACTION_TERMS as $termino) {
            if (str_contains($mensaje, $termino)) {
                return true;
            }
        }

        return false;
    }

    private function isUserRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'usuario') || str_contains($mensaje, 'empleado') || str_contains($mensaje, 'cajero');
    }

    private function isReportRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'reporte') || str_contains($mensaje, 'resumen') || str_contains($mensaje, 'facturacion') || str_contains($mensaje, 'facturación');
    }

    private function isCashRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'caja') || str_contains($mensaje, 'efectivo') || str_contains($mensaje, 'monto esperado');
    }

    private function isSaleRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'venta') || str_contains($mensaje, 'vendido') || str_contains($mensaje, 'ingreso');
    }

    private function isClientRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'cliente') || str_contains($mensaje, 'dni') || str_contains($mensaje, 'ruc');
    }

    private function isInventoryAlertRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'lote') || str_contains($mensaje, 'venc') || str_contains($mensaje, 'critico') || str_contains($mensaje, 'crítico');
    }

    private function isCatalogRequest(string $mensaje): bool
    {
        return str_contains($mensaje, 'producto')
            || str_contains($mensaje, 'catalogo')
            || str_contains($mensaje, 'catálogo')
            || str_contains($mensaje, 'inventario')
            || str_contains($mensaje, 'stock')
            || str_contains($mensaje, 'disponible')
            || str_contains($mensaje, 'precio')
            || str_contains($mensaje, 'principio activo');
    }

    private function extractKeywords(string $mensaje): array
    {
        $tokens = array_filter(explode(' ', preg_replace('/[^\pL\pN\s]/u', '', $mensaje)));

        return array_values(array_filter($tokens, fn (string $token) => mb_strlen($token) > 2 && !in_array($token, self::QUERY_STOP_WORDS, true)));
    }

    private function isAdmin(User $usuario): bool
    {
        return $usuario->role === 'admin';
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim($texto));
    }
}
