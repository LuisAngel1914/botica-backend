<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\Cliente;
use App\Models\DetalleVenta;
use App\Models\DetalleVentaLote;
use App\Models\InventoryMovement;
use App\Models\Lote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Services\ActivityLogger;

class VentaController extends Controller
{
    public function index()
    {
        try {
            $ventas = Venta::with(['cliente', 'detalles.producto', 'detalles.asignaciones.lote'])
                ->orderBy('id', 'desc')
                ->get();

            return response()->json($ventas, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener ventas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request) 
    {
        $request->validate([
            'usuario_id'             => 'nullable|integer',
            'cliente_id'             => 'nullable',
            'metodo_pago'            => 'nullable|string',
            'detalles'               => 'required|array|min:1',
            'detalles.*.producto_id' => 'required|exists:productos,id',
            'detalles.*.cantidad'    => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($request) {
            $totalVenta = 0;
            $detallesParaInsertar = [];

            // 1. Procesar productos con FEFO y guardar el lote exacto de cada unidad vendida.
            foreach ($request->detalles as $item) {
                $producto = Producto::lockForUpdate()->findOrFail($item['producto_id']);

                if ($producto->stock_actual < $item['cantidad']) {
                    throw ValidationException::withMessages(['detalles' => "Stock insuficiente para: {$producto->nombre}"]);
                }

                $subtotal = $producto->precio_venta * $item['cantidad'];
                $totalVenta += $subtotal;
                $cantidadPendiente = $item['cantidad'];
                $asignaciones = [];

                $lotes = Lote::where('producto_id', $producto->id)
                    ->where('stock', '>', 0)
                    ->whereDate('fecha_vencimiento', '>=', Carbon::today())
                    ->orderBy('fecha_vencimiento')
                    ->lockForUpdate()
                    ->get();

                foreach ($lotes as $lote) {
                    if ($cantidadPendiente <= 0) {
                        break;
                    }

                    $cantidadAsignada = min($lote->stock, $cantidadPendiente);
                    $lote->decrement('stock', $cantidadAsignada);
                    $cantidadPendiente -= $cantidadAsignada;
                    $asignaciones[] = ['lote_id' => $lote->id, 'cantidad' => $cantidadAsignada];
                }

                if ($cantidadPendiente > 0) {
                    throw ValidationException::withMessages(['detalles' => "No hay lotes vigentes suficientes para: {$producto->nombre}"]);
                }

                $producto->decrement('stock_actual', $item['cantidad']);
                $detallesParaInsertar[] = [
                    'producto_id' => $producto->id,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $producto->precio_venta,
                    'costo_unitario' => $producto->precio_compra,
                    'subtotal' => $subtotal,
                    'asignaciones' => $asignaciones,
                ];
            }

            // 2. Lógica Automática de Cliente
            $clienteId = null;

            if ($request->filled('cliente_id')) {
                $clienteId = $request->cliente_id;
            } elseif ($request->filled('cliente_datos') && !empty($request->cliente_datos['numero_documento'])) {
                $datos = $request->cliente_datos;
                $clienteNuevo = Cliente::firstOrCreate(
                    ['numero_documento' => $datos['numero_documento']],
                    [
                        'tipo_documento'      => $datos['tipo_documento'],
                        'nombre_razon_social' => $datos['nombre_razon_social'],
                        'direccion'           => '-'
                    ]
                );
                $clienteId = $clienteNuevo->id;
            } else {
                $clienteGenerico = Cliente::firstOrCreate(
                    ['numero_documento' => '00000000'],
                    [
                        'tipo_documento'      => 'DNI',
                        'nombre_razon_social' => 'CLIENTE VARIOS',
                        'direccion'           => '-'
                    ]
                );
                $clienteId = $clienteGenerico->id;
            }

            // 3. Registrar la Venta
            $ultimoId = Venta::max('id') ?? 0;
            $numeroComprobante = 'B001-' . str_pad($ultimoId + 1, 6, '0', STR_PAD_LEFT);
            $userId = auth()->id() ?? $request->usuario_id ?? 1;

            $venta = Venta::create([
                'usuario_id'         => $userId,
                'cliente_id'         => $clienteId,
                'numero_comprobante' => $numeroComprobante,
                'total'              => $totalVenta,
                'metodo_pago'        => $request->metodo_pago ?? 'Efectivo',
                'estado'             => 'completada',
            ]);

            foreach ($detallesParaInsertar as $detalle) {
                $asignaciones = $detalle['asignaciones'];
                unset($detalle['asignaciones']);

                $detalleVenta = $venta->detalles()->create($detalle);

                foreach ($asignaciones as $asignacion) {
                    DetalleVentaLote::create([
                        'detalle_venta_id' => $detalleVenta->id,
                        'lote_id' => $asignacion['lote_id'],
                        'cantidad' => $asignacion['cantidad'],
                    ]);

                    InventoryMovement::create([
                        'producto_id' => $detalle['producto_id'],
                        'lote_id' => $asignacion['lote_id'],
                        'user_id' => $userId,
                        'tipo' => 'venta',
                        'cantidad' => -$asignacion['cantidad'],
                        'referencia' => 'Venta #' . $venta->id,
                    ]);
                }
            }

            ActivityLogger::log($request, 'sale.created', Venta::class, $venta->id, ['total' => (float) $venta->total, 'metodo_pago' => $venta->metodo_pago, 'items' => count($detallesParaInsertar), 'lotes_asignados' => true]);

            return response()->json([
                'message'  => 'Venta registrada con éxito',
                'venta_id' => $venta->id,
                'id'       => $venta->id,
                'data'     => $venta->load(['cliente', 'detalles.producto', 'detalles.asignaciones.lote'])
            ], 201);
        });
    }

    public function cancelar(Request $request, $id)
    {
        $data = $request->validate(['motivo' => 'required|string|min:10|max:1000']);
        return DB::transaction(function () use ($id, $request, $data) {
            $venta = Venta::with('detalles.asignaciones.lote')->find($id);

            if (!$venta) {
                return response()->json(['message' => 'Venta no encontrada'], 404);
            }

            if ($venta->estado === 'anulada') {
                return response()->json(['message' => 'La venta ya se encuentra anulada'], 400);
            }

            foreach ($venta->detalles as $detalle) {
                $producto = Producto::lockForUpdate()->find($detalle->producto_id);
                if (!$producto) {
                    continue;
                }

                $producto->increment('stock_actual', $detalle->cantidad);
                $asignaciones = $detalle->asignaciones;

                if ($asignaciones->isNotEmpty()) {
                    foreach ($asignaciones as $asignacion) {
                        $lote = Lote::lockForUpdate()->find($asignacion->lote_id);
                        if (!$lote) {
                            continue;
                        }

                        $lote->increment('stock', $asignacion->cantidad);
                        InventoryMovement::create([
                            'producto_id' => $producto->id,
                            'lote_id' => $lote->id,
                            'user_id' => $request->user()->id,
                            'tipo' => 'anulacion_venta',
                            'cantidad' => $asignacion->cantidad,
                            'referencia' => 'Venta #' . $venta->id,
                            'motivo' => $data['motivo'],
                        ]);
                    }

                    continue;
                }

                // Compatibilidad: las ventas históricas no tenían asignación por lote.
                $lote = Lote::where('producto_id', $producto->id)
                    ->orderByDesc('fecha_vencimiento')
                    ->lockForUpdate()
                    ->first();

                if ($lote) {
                    $lote->increment('stock', $detalle->cantidad);
                }

                InventoryMovement::create([
                    'producto_id' => $producto->id,
                    'lote_id' => $lote?->id,
                    'user_id' => $request->user()->id,
                    'tipo' => 'anulacion_venta',
                    'cantidad' => $detalle->cantidad,
                    'referencia' => 'Venta #' . $venta->id,
                    'motivo' => $data['motivo'] . ' (venta histórica sin lote asignado)',
                ]);
            }

            $venta->update(['estado' => 'anulada']);

            ActivityLogger::log($request, 'sale.cancelled', Venta::class, $venta->id, ['total' => (float) $venta->total, 'motivo' => $data['motivo'], 'lotes_restaurados' => true]);

            return response()->json([
                'message' => 'Venta anulada correctamente y stock repuesto',
                'venta'   => $venta
            ], 200);
        });
    }

    public function reporteDiario(Request $request)
    {
        $fecha = $request->get('fecha', Carbon::today()->toDateString());

        $ventasCompletadas = Venta::with('cliente')
            ->whereDate('created_at', $fecha)
            ->where('estado', 'completada')
            ->get();

        $totalesPorMetodo = $ventasCompletadas->groupBy('metodo_pago')->map(function ($row) {
            return $row->sum('total');
        });

        $ventasAnuladas = Venta::whereDate('created_at', $fecha)
            ->where('estado', 'anulada')
            ->count();

        return response()->json([
            'fecha'           => $fecha,
            'total_general'   => $ventasCompletadas->sum('total'),
            'cantidad_ventas' => $ventasCompletadas->count(),
            'ventas_anuladas' => $ventasAnuladas,
            'desglose_pagos'  => $totalesPorMetodo,
            'ventas'          => $ventasCompletadas,
        ], 200);
    }

    public function ticket(Request $request, $id)
    {
        $venta = Venta::with(['cliente', 'detalles.producto'])->find($id);

        if (!$venta) {
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        $usuario = $request->user();
        if ($usuario->role !== 'admin' && $venta->usuario_id !== $usuario->id) {
            return response()->json(['message' => 'No tienes permiso para consultar este ticket.'], 403);
        }

        return view('tickets.venta', compact('venta'));
    }
}