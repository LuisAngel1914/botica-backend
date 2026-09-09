<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ReporteController extends Controller
{
    public function resumen()
    {
        $hoy = Carbon::today();

        $ventasHoy = Venta::query()
            ->whereDate('created_at', $hoy)
            ->where('estado', 'completada');

        $pagos = (clone $ventasHoy)
            ->select('metodo_pago', DB::raw('COALESCE(SUM(total), 0) as monto'))
            ->groupBy('metodo_pago')
            ->pluck('monto', 'metodo_pago');

        return response()->json([
            'total_ventas_hoy' => (float) (clone $ventasHoy)->sum('total'),
            'transacciones_hoy' => (int) (clone $ventasHoy)->count(),
            'total_efectivo' => (float) $pagos->get('Efectivo', 0),
            'total_digital' => (float) ($pagos->get('Yape', 0) + $pagos->get('Plin', 0) + $pagos->get('Tarjeta', 0)),
            'top_productos' => $this->topProductosDelMes(),
            'desglose_pagos' => $pagos,
        ]);
    }

    public function dashboard()
    {
        $hoy = Carbon::today();
        $inicioMes = Carbon::now()->startOfMonth();

        $ventasHoy = Venta::query()
            ->whereDate('created_at', $hoy)
            ->where('estado', 'completada');

        $pagos = (clone $ventasHoy)
            ->select('metodo_pago', DB::raw('COALESCE(SUM(total), 0) as monto'))
            ->groupBy('metodo_pago')
            ->pluck('monto', 'metodo_pago');

        $stockCritico = Producto::query()
            ->whereColumn('stock_actual', '<=', 'stock_minimo')
            ->orderBy('stock_actual')
            ->limit(5)
            ->get(['id', 'nombre', 'stock_actual', 'stock_minimo', 'imagen_url']);

        $proximosAVencer = Lote::query()
            ->with('producto:id,nombre,imagen_url')
            ->where('stock', '>', 0)
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->whereDate('fecha_vencimiento', '<=', Carbon::now()->addDays(60))
            ->orderBy('fecha_vencimiento')
            ->limit(5)
            ->get(['id', 'producto_id', 'numero_lote', 'stock', 'fecha_vencimiento']);

        return response()->json([
            'resumen_caja' => [
                'ventas_hoy_monto' => (float) (clone $ventasHoy)->sum('total'),
                'ventas_hoy_cantidad' => (int) (clone $ventasHoy)->count(),
                'ventas_mes_monto' => (float) Venta::where('estado', 'completada')->where('created_at', '>=', $inicioMes)->sum('total'),
                'total_clientes' => Cliente::count(),
            ],
            'alertas_inventario' => [
                'total_stock_critico' => Producto::whereColumn('stock_actual', '<=', 'stock_minimo')->count(),
                'total_por_vencer' => Lote::where('stock', '>', 0)->whereDate('fecha_vencimiento', '>=', $hoy)->whereDate('fecha_vencimiento', '<=', Carbon::now()->addDays(60))->count(),
            ],
            'productos_stock_critico' => $stockCritico,
            'productos_por_vencer' => $proximosAVencer,
            'top_productos' => $this->topProductosDelMes(),
            'desglose_pagos' => $pagos,
            'ultimas_ventas' => Venta::with('cliente')->where('estado', 'completada')->latest()->take(5)->get(),
        ]);
    }

    private function topProductosDelMes()
    {
        return DB::table('detalle_ventas')
            ->join('ventas', 'detalle_ventas.venta_id', '=', 'ventas.id')
            ->join('productos', 'detalle_ventas.producto_id', '=', 'productos.id')
            ->where('ventas.estado', 'completada')
            ->where('ventas.created_at', '>=', Carbon::now()->startOfMonth())
            ->select(
                'productos.id',
                'productos.nombre',
                'productos.imagen_url',
                DB::raw('SUM(detalle_ventas.cantidad) as unidades'),
                DB::raw('SUM(detalle_ventas.subtotal) as monto')
            )
            ->groupBy('productos.id', 'productos.nombre', 'productos.imagen_url')
            ->orderByDesc('unidades')
            ->limit(5)
            ->get();
    }

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

    public function exportarPdf(Request $request)
    {
        $ventas = $this->filtrarVentas($request);
        $totalVentas = $ventas->sum('total');

        return Pdf::loadView('pdf.reporte_ventas', compact('ventas', 'totalVentas'))
            ->download('reporte_ventas_' . Carbon::now()->format('Ymd_His') . '.pdf');
    }

    public function exportarExcel(Request $request)
    {
        $ventas = $this->filtrarVentas($request);
        $filename = 'reporte_ventas_' . Carbon::now()->format('Ymd_His') . '.csv';

        return response()->stream(function () use ($ventas) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, ['ID Venta', 'Fecha', 'Cliente', 'Método Pago', 'Total (S/)']);

            foreach ($ventas as $venta) {
                fputcsv($file, [
                    $venta->id,
                    $venta->created_at->format('d/m/Y H:i'),
                    $venta->cliente->nombre_razon_social ?? $venta->cliente->nombre ?? 'Cliente eventual',
                    $venta->metodo_pago,
                    number_format($venta->total, 2),
                ]);
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=$filename",
        ]);
    }

    public function enviarCorreo(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $ventas = $this->filtrarVentas($request);
        $totalVentas = $ventas->sum('total');
        $pdf = Pdf::loadView('pdf.reporte_ventas', compact('ventas', 'totalVentas'));

        Mail::send([], [], function ($message) use ($request, $pdf) {
            $message->to($request->email)
                ->subject('Reporte de Ventas - Botica')
                ->html('Adjunto encontrarás el reporte de ventas generado en PDF.')
                ->attachData($pdf->output(), 'Reporte_Ventas.pdf', ['mime' => 'application/pdf']);
        });

        return response()->json(['message' => 'Reporte enviado con éxito.']);
    }
}
