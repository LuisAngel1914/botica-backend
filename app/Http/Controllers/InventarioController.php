<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Lote;
use Illuminate\Http\Request;
use Carbon\Carbon;

class InventarioController extends Controller
{
    // Listar productos con sus lotes y alerta de vencimiento
    public function index()
    {
        $productos = Producto::with(['lotes' => function($q) {
            $q->where('stock', '>', 0)->orderBy('fecha_vencimiento', 'asc');
        }])->get();

        return response()->json($productos);
    }

    // Registrar nuevo lote / entrada de stock
    public function registrarLote(Request $request)
    {
        $request->validate([
            'producto_id'       => 'required|exists:productos,id',
            'numero_lote'       => 'required|string',
            'stock'             => 'required|integer|min:1',
            'fecha_vencimiento' => 'required|date',
        ]);

        $lote = Lote::create($request->all());

        // Actualizar el stock total del producto
        $producto = Producto::find($request->producto_id);
        $producto->increment('stock_actual', $request->stock);

        return response()->json(['message' => 'Lote ingresado con éxito', 'lote' => $lote], 201);
    }

    // Productos próximos a vencer (alertas a 60 días)
    public function porVencer()
    {
        $limite = Carbon::now()->addDays(60);

        $lotes = Lote::with('producto')
            ->where('stock', '>', 0)
            ->where('fecha_vencimiento', '<=', $limite)
            ->orderBy('fecha_vencimiento', 'asc')
            ->get();

        return response()->json($lotes);
    }
}