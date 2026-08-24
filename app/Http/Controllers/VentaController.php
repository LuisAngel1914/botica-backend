<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaController extends Controller
{
    // Listar historial de ventas
    public function index()
    {
        return response()->json(Venta::all(), 200);
    }

    // Registrar una nueva venta y descontar stock
    public function store(Request $request)
    {
        $request->validate([
            'usuario_id' => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad' => 'required|integer|min:1',
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

        // Transacción para asegurar consistencia
        DB::transaction(function () use ($request, $producto, &$venta) {
            $total = $producto->precio_venta * $request->cantidad;

            // 1. Crear el registro de la venta
            $venta = Venta::create([
                'usuario_id' => $request->usuario_id,
                'producto_id' => $request->producto_id,
                'cantidad' => $request->cantidad,
                'precio_unitario' => $producto->precio_venta,
                'total' => $total,
                'fecha_venta' => now(),
            ]);

            // 2. Descontar el stock del producto
            $producto->decrement('stock_actual', $request->cantidad);
        });

        return response()->json([
            'message' => 'Venta registrada con éxito.',
            'data' => $venta,
            'nuevo_stock' => $producto->fresh()->stock_actual
        ], 201);
    }
}