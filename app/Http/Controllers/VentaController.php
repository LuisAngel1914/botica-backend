<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaController extends Controller
{
    public function index()
    {
        return response()->json(Venta::all(), 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'usuario_id'  => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad'    => 'required|integer|min:1',
            'metodo_pago' => 'nullable|string|in:Efectivo,Yape,Plin,Tarjeta',
        ]);

        $producto = Producto::find($request->producto_id);

        if (!$producto) {
            return response()->json(['message' => 'El producto no existe.'], 404);
        }

        if ($producto->stock_actual < $request->cantidad) {
            return response()->json([
                'message' => 'Stock insuficiente.',
                'stock_disponible' => $producto->stock_actual
            ], 400);
        }

        $venta = DB::transaction(function () use ($request, $producto) {
            $totalCalculado = $producto->precio_venta * $request->cantidad;

            // Si no envían cliente_id, asignamos 1 (Cliente varios / Público general)
            $nuevaVenta = Venta::create([
                'usuario_id'  => $request->usuario_id,
                'cliente_id'  => $request->cliente_id ?? 1,
                'total'       => $totalCalculado,
                'metodo_pago' => $request->metodo_pago ?? 'Efectivo',
                'estado'      => 'completada',
            ]);

            $producto->decrement('stock_actual', $request->cantidad);

            return $nuevaVenta;
        });

        return response()->json([
            'message'     => 'Venta registrada con éxito.',
            'data'        => $venta,
            'nuevo_stock' => $producto->fresh()->stock_actual
        ], 201);
    }
}