<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReporteController extends Controller
{
    // Obtener métricas y alertas del inventario
    public function dashboard()
    {
        // 1. Productos con stock igual o menor al stock mínimo (ej. Amoxicilina)
        $stockCritico = Producto::whereColumn('stock_actual', '<=', 'stock_minimo')->get();

        // 2. Productos por vencer en los próximos 90 días
        $proximosAVencer = Producto::where('fecha_vencimiento', '<=', Carbon::now()->addDays(90))
                                   ->where('fecha_vencimiento', '>=', Carbon::now())
                                   ->get();

        return response()->json([
            'alertas' => [
                'total_stock_critico' => $stockCritico->count(),
                'total_por_vencer'    => $proximosAVencer->count(),
            ],
            'productos_stock_critico' => $stockCritico,
            'productos_por_vencer'    => $proximosAVencer,
        ], 200);
    }
}