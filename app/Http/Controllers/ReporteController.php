<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Venta;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;

class ReporteController extends Controller
{
    // Métricas dinámicas para el dashboard de Vue (ReportesView.vue)
    public function resumen()
    {
        try {
            $hoy = Carbon::today();

            // 1. Total Ventas (Intenta filtrar por hoy, si no hay registros trae el total)
            $ventasQuery = DB::table('ventas')->whereDate('created_at', $hoy);
            
            if ($ventasQuery->count() == 0) {
                $ventasQuery = DB::table('ventas'); // Respaldo para entorno de pruebas
            }

            $ventasTotales = $ventasQuery->select(
                DB::raw('COUNT(id) as transacciones'),
                DB::raw('COALESCE(SUM(total), 0) as total')
            )->first();

            // 2. Métodos de Pago
            $pagos = DB::table('ventas')
                ->select('metodo_pago', DB::raw('COALESCE(SUM(total), 0) as monto'))
                ->groupBy('metodo_pago')
                ->pluck('monto', 'metodo_pago');

            $totalEfectivo = $pagos->get('Efectivo', 0);
            $totalDigital = ($pagos->get('Yape', 0) + $pagos->get('Plin', 0) + $pagos->get('Tarjeta', 0));

            // 3. Conteo de Recetas (Simples transacciones)
            $totalRecetas = DB::table('ventas')->where('metodo_pago', '!=', '')->count();

            // 4. Top 5 Productos Más Vendidos
            $topProductos = DB::table('detalle_ventas')
                ->join('productos', 'detalle_ventas.producto_id', '=', 'productos.id')
                ->select(
                    'productos.nombre',
                    DB::raw('SUM(detalle_ventas.cantidad) as unidades'),
                    DB::raw('SUM(detalle_ventas.subtotal) as monto')
                )
                ->groupBy('productos.id', 'productos.nombre')
                ->orderByDesc('unidades')
                ->limit(5)
                ->get();

            return response()->json([
                'total_ventas_hoy'  => (float) $ventasTotales->total,
                'transacciones_hoy' => (int) $ventasTotales->transacciones,
                'total_efectivo'    => (float) $totalEfectivo,
                'total_digital'     => (float) $totalDigital,
                'total_recetas'     => (int) $totalRecetas,
                'top_productos'     => $topProductos,
                'desglose_pagos'    => $pagos
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    // Métricas de inventario y caja para el Dashboard general
    public function dashboard()
    {
        $hoy = Carbon::today();
        $mesActual = Carbon::now()->month;

        // 1. Alertas de Inventario
        $stockCritico = Producto::whereColumn('stock_actual', '<=', 'stock_minimo')->get();

        $proximosAVencer = Producto::where('fecha_vencimiento', '<=', Carbon::now()->addDays(90))
                                   ->where('fecha_vencimiento', '>=', Carbon::now())
                                   ->get();

        // 2. Métricas Financieras de Ventas
        $ventasHoyMonto = Venta::whereDate('created_at', $hoy)
                                ->where('estado', 'completada')
                                ->sum('total');

        $ventasHoyCantidad = Venta::whereDate('created_at', $hoy)
                                  ->where('estado', 'completada')
                                  ->count();

        $ventasMesMonto = Venta::whereMonth('created_at', $mesActual)
                               ->where('estado', 'completada')
                               ->sum('total');

        return response()->json([
            'resumen_caja' => [
                'ventas_hoy_monto'    => $ventasHoyMonto,
                'ventas_hoy_cantidad' => $ventasHoyCantidad,
                'ventas_mes_monto'    => $ventasMesMonto,
                'total_clientes'      => Cliente::count(),
            ],
            'alertas_inventario' => [
                'total_stock_critico' => $stockCritico->count(),
                'total_por_vencer'    => $proximosAVencer->count(),
            ],
            'productos_stock_critico' => $stockCritico,
            'productos_por_vencer'    => $proximosAVencer,
            'ultimas_ventas'          => Venta::with('cliente')->latest()->take(5)->get(),
        ], 200);
    }

    // Método privado para filtrar ventas por fechas
    private function filtrarVentas(Request $request)
    {
        $query = Venta::with(['cliente']);

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('created_at', '>=', $request->fecha_inicio);
        }
        if ($request->filled('fecha_fin')) {
            $query->whereDate('created_at', '<=', $request->fecha_fin);
        }

        return $query->latest()->get();
    }

    // Descargar PDF con DomPDF
    public function exportarPdf(Request $request)
    {
        $ventas = $this->filtrarVentas($request);
        $totalVentas = $ventas->sum('total');

        $pdf = Pdf::loadView('pdf.reporte_ventas', compact('ventas', 'totalVentas'));
        return $pdf->download('reporte_ventas_' . Carbon::now()->format('Ymd_His') . '.pdf');
    }

    // Descargar Excel (CSV estructurado en UTF-8)
    public function exportarExcel(Request $request)
    {
        $ventas = $this->filtrarVentas($request);
        $filename = 'reporte_ventas_' . Carbon::now()->format('Ymd_His') . '.csv';

        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function() use ($ventas) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($file, ['ID Venta', 'Fecha', 'Cliente', 'Método Pago', 'Total (S/)']);

            foreach ($ventas as $v) {
                fputcsv($file, [
                    $v->id,
                    $v->created_at->format('d/m/Y H:i'),
                    $v->cliente->nombre_razon_social ?? $v->cliente->nombre ?? 'Cliente Eventual',
                    $v->metodo_pago,
                    number_format($v->total, 2)
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // Enviar PDF adjunto al correo electrónico
    public function enviarCorreo(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        
        $ventas = $this->filtrarVentas($request);
        $totalVentas = $ventas->sum('total');
        $email = $request->email;

        $pdf = Pdf::loadView('pdf.reporte_ventas', compact('ventas', 'totalVentas'));

        Mail::send([], [], function ($message) use ($email, $pdf) {
            $message->to($email)
                    ->subject('Reporte de Ventas - Botica')
                    ->html('Adjunto encontrarás el reporte de ventas generado en PDF.')
                    ->attachData($pdf->output(), 'Reporte_Ventas.pdf', [
                        'mime' => 'application/pdf',
                    ]);
        });

        return response()->json(['message' => 'Reporte enviado con éxito a ' . $email], 200);
    }
}