<?php

namespace App\Http\Controllers;

use App\Models\Caja;
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

        $vencidos = Lote::query()
            ->with('producto:id,nombre,imagen_url')
            ->where('stock', '>', 0)
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->orderBy('fecha_vencimiento')
            ->limit(5)
            ->get(['id', 'producto_id', 'numero_lote', 'stock', 'fecha_vencimiento']);

        $cajaActiva = Caja::with('usuario:id,name')->where('estado', 'abierta')->latest()->first();
        $estadoCaja = [
            'estado' => 'cerrada',
            'mensaje' => 'No hay caja abierta actualmente.',
        ];

        if ($cajaActiva) {
            $fechaApertura = $cajaActiva->fecha_apertura ?? $cajaActiva->created_at;
            $ventasEfectivoCaja = (float) Venta::query()
                ->where('created_at', '>=', $fechaApertura)
                ->where('metodo_pago', 'Efectivo')
                ->where(fn ($query) => $query->where('estado', 'completada')->orWhereNull('estado'))
                ->sum('total');

            $estadoCaja = [
                'estado' => 'abierta',
                'caja_id' => $cajaActiva->id,
                'responsable' => $cajaActiva->usuario?->name ?? 'Operador',
                'fecha_apertura' => Carbon::parse($fechaApertura)->toIso8601String(),
                'monto_inicial' => (float) $cajaActiva->monto_inicial,
                'ventas_efectivo' => $ventasEfectivoCaja,
                'monto_esperado' => (float) $cajaActiva->monto_inicial + $ventasEfectivoCaja,
            ];
        }

        $rentabilidad = $this->rentabilidadMensual($inicioMes);
        $productosRentables = $this->productosRentablesDelMes($inicioMes);

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
                'total_vencidos' => Lote::where('stock', '>', 0)->whereDate('fecha_vencimiento', '<', $hoy)->count(),
            ],
            'estado_caja' => $estadoCaja,
            'productos_stock_critico' => $stockCritico,
            'productos_por_vencer' => $proximosAVencer,
            'productos_vencidos' => $vencidos,
            'top_productos' => $this->topProductosDelMes(),
            'rentabilidad' => $rentabilidad,
            'productos_rentables' => $productosRentables,
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

    private function rentabilidadMensual(Carbon $inicioMes): array
    {
        $row = DB::table('detalle_ventas as detalle')
            ->join('ventas as venta', 'detalle.venta_id', '=', 'venta.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('venta.estado', 'completada')
            ->where('venta.created_at', '>=', $inicioMes)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle.subtotal ELSE 0 END), 0) as ingresos_confirmados,
                COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle.cantidad * detalle.costo_unitario ELSE 0 END), 0) as costos_confirmados,
                COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle.subtotal ELSE 0 END), 0) as ingresos_estimados,
                COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle.cantidad * producto.precio_compra ELSE 0 END), 0) as costos_estimados
            ')
            ->first();

        return [
            'margen_confirmado' => (float) $row->ingresos_confirmados - (float) $row->costos_confirmados,
            'margen_estimado_historico' => (float) $row->ingresos_estimados - (float) $row->costos_estimados,
            'ingresos_confirmados' => (float) $row->ingresos_confirmados,
            'ingresos_estimados_historico' => (float) $row->ingresos_estimados,
        ];
    }

    private function productosRentablesDelMes(Carbon $inicioMes)
    {
        return DB::table('detalle_ventas as detalle')
            ->join('ventas as venta', 'detalle.venta_id', '=', 'venta.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('venta.estado', 'completada')
            ->where('venta.created_at', '>=', $inicioMes)
            ->select(
                'producto.id',
                'producto.nombre',
                'producto.imagen_url',
                DB::raw('SUM(detalle.cantidad) as unidades'),
                DB::raw('SUM(detalle.subtotal) as ingresos'),
                DB::raw('SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle.subtotal - (detalle.cantidad * detalle.costo_unitario) ELSE 0 END) as margen_confirmado'),
                DB::raw('SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle.subtotal - (detalle.cantidad * producto.precio_compra) ELSE 0 END) as margen_estimado_historico'),
                DB::raw('SUM(CASE WHEN detalle.costo_unitario IS NULL THEN 1 ELSE 0 END) as lineas_estimadas')
            )
            ->groupBy('producto.id', 'producto.nombre', 'producto.imagen_url')
            ->orderByDesc(DB::raw('SUM(detalle.subtotal - (detalle.cantidad * COALESCE(detalle.costo_unitario, producto.precio_compra)))'))
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
