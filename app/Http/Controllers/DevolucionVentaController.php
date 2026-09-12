<?php

namespace App\Http\Controllers;

use App\Models\DetalleDevolucionLote;
use App\Models\DetalleDevolucionVenta;
use App\Models\DevolucionVenta;
use App\Models\InventoryMovement;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DevolucionVentaController extends Controller
{
    public function store(Request $request, Venta $venta)
    {
        $data = $request->validate([
            'motivo' => 'required|string|min:10|max:1000',
            'detalles' => 'required|array|min:1',
            'detalles.*.detalle_venta_id' => 'required|integer|distinct',
            'detalles.*.cantidad' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($data, $request, $venta) {
            $venta = Venta::with([
                'detalles.asignaciones',
                'detalles.devoluciones.asignaciones',
            ])->lockForUpdate()->findOrFail($venta->id);

            if ($venta->estado !== 'completada') {
                throw ValidationException::withMessages(['venta' => 'Solo se pueden devolver ventas completadas.']);
            }

            $detallesVenta = $venta->detalles->keyBy('id');
            $plan = [];
            $totalDevolucion = 0;

            foreach ($data['detalles'] as $item) {
                $detalle = $detallesVenta->get($item['detalle_venta_id']);
                if (!$detalle) {
                    throw ValidationException::withMessages(['detalles' => 'Uno de los productos no pertenece a esta venta.']);
                }

                if ($detalle->asignaciones->isEmpty()) {
                    throw ValidationException::withMessages(['detalles' => 'Esta venta histórica no tiene lotes registrados; usa la anulación completa para mantener la trazabilidad.']);
                }

                $cantidadDevuelta = $detalle->devoluciones->sum('cantidad');
                $cantidadDisponible = $detalle->cantidad - $cantidadDevuelta;
                if ($item['cantidad'] > $cantidadDisponible) {
                    throw ValidationException::withMessages(['detalles' => "La devolución supera las unidades disponibles de {$detalle->producto_id}."]);
                }

                $devueltoPorAsignacion = $detalle->devoluciones
                    ->flatMap(fn ($devolucion) => $devolucion->asignaciones)
                    ->groupBy('detalle_venta_lote_id')
                    ->map(fn ($asignaciones) => $asignaciones->sum('cantidad'));

                $cantidadPendiente = $item['cantidad'];
                $asignaciones = [];

                foreach ($detalle->asignaciones as $asignacionVenta) {
                    if ($cantidadPendiente <= 0) {
                        break;
                    }

                    $cantidadDisponibleLote = $asignacionVenta->cantidad - ($devueltoPorAsignacion->get($asignacionVenta->id) ?? 0);
                    $cantidadAsignada = min($cantidadDisponibleLote, $cantidadPendiente);
                    if ($cantidadAsignada <= 0) {
                        continue;
                    }

                    $asignaciones[] = [
                        'detalle_venta_lote_id' => $asignacionVenta->id,
                        'lote_id' => $asignacionVenta->lote_id,
                        'cantidad' => $cantidadAsignada,
                    ];
                    $cantidadPendiente -= $cantidadAsignada;
                }

                if ($cantidadPendiente > 0) {
                    throw ValidationException::withMessages(['detalles' => 'No se pudo asociar la devolución a los lotes originales.']);
                }

                $subtotal = $detalle->precio_unitario * $item['cantidad'];
                $totalDevolucion += $subtotal;
                $plan[] = compact('detalle', 'asignaciones', 'subtotal') + ['cantidad' => $item['cantidad']];
            }

            $devolucion = DevolucionVenta::create([
                'venta_id' => $venta->id,
                'user_id' => $request->user()->id,
                'total' => $totalDevolucion,
                'motivo' => $data['motivo'],
            ]);

            foreach ($plan as $item) {
                $detalleDevolucion = DetalleDevolucionVenta::create([
                    'devolucion_venta_id' => $devolucion->id,
                    'detalle_venta_id' => $item['detalle']->id,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['detalle']->precio_unitario,
                    'subtotal' => $item['subtotal'],
                ]);

                $producto = Producto::lockForUpdate()->findOrFail($item['detalle']->producto_id);
                $producto->increment('stock_actual', $item['cantidad']);

                foreach ($item['asignaciones'] as $asignacion) {
                    $lote = Lote::lockForUpdate()->findOrFail($asignacion['lote_id']);
                    $lote->increment('stock', $asignacion['cantidad']);

                    DetalleDevolucionLote::create([
                        'detalle_devolucion_venta_id' => $detalleDevolucion->id,
                        ...$asignacion,
                    ]);

                    InventoryMovement::create([
                        'producto_id' => $producto->id,
                        'lote_id' => $lote->id,
                        'user_id' => $request->user()->id,
                        'tipo' => 'devolucion_venta',
                        'cantidad' => $asignacion['cantidad'],
                        'referencia' => 'Devolución #' . $devolucion->id . ' · Venta #' . $venta->id,
                        'motivo' => $data['motivo'],
                    ]);
                }
            }

            ActivityLogger::log($request, 'sale.partially_returned', DevolucionVenta::class, $devolucion->id, [
                'venta_id' => $venta->id,
                'total' => (float) $devolucion->total,
                'metodo_reembolso' => $venta->metodo_pago,
                'motivo' => $data['motivo'],
            ]);

            return response()->json([
                'message' => 'Devolución registrada y stock repuesto en los lotes originales.',
                'devolucion' => $devolucion->load('detalles.asignaciones.lote'),
            ], 201);
        });
    }
}
