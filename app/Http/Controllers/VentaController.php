<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\Cliente;
use App\Models\DetalleVenta;
use App\Models\Lote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VentaController extends Controller
{
    public function index()
    {
        try {
            $ventas = Venta::with(['cliente', 'detalles.producto'])
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

            // 1. Procesar Productos y Descuento FEFO
            foreach ($request->detalles as $item) {
                $producto = Producto::findOrFail($item['producto_id']);

                if ($producto->stock_actual < $item['cantidad']) {
                    throw new \Exception("Stock insuficiente para: {$producto->nombre}");
                }

                $subtotal = $producto->precio_venta * $item['cantidad'];
                $totalVenta += $subtotal;

                $detallesParaInsertar[] = [
                    'producto_id'     => $producto->id,
                    'cantidad'        => $item['cantidad'],
                    'precio_unitario' => $producto->precio_venta,
                    'subtotal'        => $subtotal,
                ];

                // --- LÓGICA FEFO DE LOTES ---
                // Obtener los lotes con stock ordenados por la fecha de vencimiento más cercana
                $lotes = Lote::where('producto_id', $producto->id)
                    ->where('stock', '>', 0)
                    ->orderBy('fecha_vencimiento', 'asc')
                    ->get();

                $cantidadPendiente = $item['cantidad'];

                foreach ($lotes as $lote) {
                    if ($cantidadPendiente <= 0) break;

                    if ($lote->stock >= $cantidadPendiente) {
                        $lote->decrement('stock', $cantidadPendiente);
                        $cantidadPendiente = 0;
                    } else {
                        $cantidadPendiente -= $lote->stock;
                        $lote->update(['stock' => 0]);
                    }
                }

                // Descontar del stock general del producto
                $producto->decrement('stock_actual', $item['cantidad']);
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
                $venta->detalles()->create($detalle);
            }

            return response()->json([
                'message'  => 'Venta registrada con éxito',
                'venta_id' => $venta->id,
                'id'       => $venta->id,
                'data'     => $venta->load(['cliente', 'detalles.producto'])
            ], 201);
        });
    }

    public function cancelar($id)
    {
        return DB::transaction(function () use ($id) {
            $venta = Venta::with('detalles')->find($id);

            if (!$venta) {
                return response()->json(['message' => 'Venta no encontrada'], 404);
            }

            if ($venta->estado === 'anulada') {
                return response()->json(['message' => 'La venta ya se encuentra anulada'], 400);
            }

            foreach ($venta->detalles as $detalle) {
                $producto = Producto::find($detalle->producto_id);
                if ($producto) {
                    $producto->increment('stock_actual', $detalle->cantidad);

                    // Devolver stock al lote más reciente al anular la venta
                    $lote = Lote::where('producto_id', $producto->id)
                        ->orderBy('fecha_vencimiento', 'desc')
                        ->first();

                    if ($lote) {
                        $lote->increment('stock', $detalle->cantidad);
                    }
                }
            }

            $venta->update(['estado' => 'anulada']);

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

    public function ticket($id)
    {
        $venta = Venta::with(['cliente', 'detalles.producto'])->find($id);

        if (!$venta) {
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        return view('tickets.venta', compact('venta'));
    }
}