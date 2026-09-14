<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\DevolucionVenta;
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

        $pagos = $this->pagosNetos($ventasHoy, $hoy);

        return response()->json([
            'total_ventas_hoy' => (float) (clone $ventasHoy)->sum('total') - $this->totalDevoluciones($hoy),
            'transacciones_hoy' => (int) (clone $ventasHoy)->count(),
            'total_efectivo' => (float) $pagos->get('Efectivo', 0),
            'total_digital' => (float) ($pagos->get('Yape', 0) + $pagos->get('Plin', 0) + $pagos->get('Tarjeta', 0)),
            'devoluciones_hoy' => $this->totalDevoluciones($hoy),
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

        $pagos = $this->pagosNetos($ventasHoy, $hoy);
        $devolucionesHoy = $this->totalDevoluciones($hoy);
        $devolucionesMes = $this->totalDevoluciones($inicioMes, false);

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
            $ventasEfectivoBrutas = (float) Venta::query()
                ->where('created_at', '>=', $fechaApertura)
                ->where('metodo_pago', 'Efectivo')
                ->where(fn ($query) => $query->where('estado', 'completada')->orWhereNull('estado'))
                ->sum('total');

            $devolucionesEfectivo = (float) DevolucionVenta::query()
                ->where('created_at', '>=', $fechaApertura)
                ->whereHas('venta', fn ($query) => $query->where('metodo_pago', 'Efectivo'))
                ->sum('total');

            $ventasEfectivoNetas = $ventasEfectivoBrutas - $devolucionesEfectivo;

            $estadoCaja = [
                'estado' => 'abierta',
                'caja_id' => $cajaActiva->id,
                'responsable' => $cajaActiva->usuario?->name ?? 'Operador',
                'fecha_apertura' => Carbon::parse($fechaApertura)->toIso8601String(),
                'monto_inicial' => (float) $cajaActiva->monto_inicial,
                'ventas_efectivo' => $ventasEfectivoNetas,
                'devoluciones_efectivo' => $devolucionesEfectivo,
                'monto_esperado' => (float) $cajaActiva->monto_inicial + $ventasEfectivoNetas,
            ];
        }

        $rentabilidad = $this->rentabilidadMensual($inicioMes);
        $productosRentables = $this->productosRentablesDelMes($inicioMes);

        return response()->json([
            'resumen_caja' => [
                'ventas_hoy_monto' => (float) (clone $ventasHoy)->sum('total') - $devolucionesHoy,
                'ventas_hoy_cantidad' => (int) (clone $ventasHoy)->count(),
                'ventas_mes_monto' => (float) Venta::where('estado', 'completada')->where('created_at', '>=', $inicioMes)->sum('total') - $devolucionesMes,
                'devoluciones_hoy' => $devolucionesHoy,
                'devoluciones_mes' => $devolucionesMes,
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

    private function totalDevoluciones(Carbon $inicio, bool $soloFecha = true): float
    {
        $query = DevolucionVenta::query();

        if ($soloFecha) {
            $query->whereDate('created_at', $inicio);
        } else {
            $query->where('created_at', '>=', $inicio);
        }

        return (float) $query->sum('total');
    }

    private function pagosNetos($ventas, Carbon $fecha)
    {
        $pagos = (clone $ventas)
            ->select('metodo_pago', DB::raw('COALESCE(SUM(total), 0) as monto'))
            ->groupBy('metodo_pago')
            ->pluck('monto', 'metodo_pago');

        $devoluciones = DevolucionVenta::query()
            ->join('ventas', 'devoluciones_venta.venta_id', '=', 'ventas.id')
            ->whereDate('devoluciones_venta.created_at', $fecha)
            ->select('ventas.metodo_pago', DB::raw('COALESCE(SUM(devoluciones_venta.total), 0) as monto'))
            ->groupBy('ventas.metodo_pago')
            ->pluck('monto', 'metodo_pago');

        return $pagos->map(fn ($monto, $metodo) => (float) $monto - (float) $devoluciones->get($metodo, 0));
    }

    private function topProductosDelMes()
    {
        $inicioMes = Carbon::now()->startOfMonth();

        $ventas = DB::table('detalle_ventas as detalle')
            ->join('ventas as venta', 'detalle.venta_id', '=', 'venta.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('venta.estado', 'completada')
            ->where('venta.created_at', '>=', $inicioMes)
            ->groupBy('producto.id', 'producto.nombre', 'producto.imagen_url')
            ->selectRaw('producto.id, producto.nombre, producto.imagen_url, SUM(detalle.cantidad) as unidades, SUM(detalle.subtotal) as monto');

        $devoluciones = DB::table('detalle_devoluciones_venta as detalle_devolucion')
            ->join('devoluciones_venta as devolucion', 'detalle_devolucion.devolucion_venta_id', '=', 'devolucion.id')
            ->join('detalle_ventas as detalle', 'detalle_devolucion.detalle_venta_id', '=', 'detalle.id')
            ->where('devolucion.created_at', '>=', $inicioMes)
            ->groupBy('detalle.producto_id')
            ->selectRaw('detalle.producto_id, SUM(detalle_devolucion.cantidad) as unidades, SUM(detalle_devolucion.subtotal) as monto');

        return DB::query()
            ->fromSub($ventas, 'ventas')
            ->leftJoinSub($devoluciones, 'devoluciones', 'devoluciones.producto_id', '=', 'ventas.id')
            ->selectRaw('ventas.id, ventas.nombre, ventas.imagen_url, ventas.unidades - COALESCE(devoluciones.unidades, 0) as unidades, ventas.monto - COALESCE(devoluciones.monto, 0) as monto')
            ->whereRaw('(ventas.unidades - COALESCE(devoluciones.unidades, 0)) > 0')
            ->orderByDesc('unidades')
            ->orderByDesc('monto')
            ->limit(5)
            ->get();
    }


    private function rentabilidadMensual(Carbon $inicioMes): array
    {
        $ventas = DB::table('detalle_ventas as detalle')
            ->join('ventas as venta', 'detalle.venta_id', '=', 'venta.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('venta.estado', 'completada')
            ->where('venta.created_at', '>=', $inicioMes)
            ->selectRaw('COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle.subtotal ELSE 0 END), 0) as ingresos_confirmados, COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle.cantidad * detalle.costo_unitario ELSE 0 END), 0) as costos_confirmados, COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle.subtotal ELSE 0 END), 0) as ingresos_estimados, COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle.cantidad * producto.precio_compra ELSE 0 END), 0) as costos_estimados')
            ->first();

        $devoluciones = DB::table('detalle_devoluciones_venta as detalle_devolucion')
            ->join('devoluciones_venta as devolucion', 'detalle_devolucion.devolucion_venta_id', '=', 'devolucion.id')
            ->join('detalle_ventas as detalle', 'detalle_devolucion.detalle_venta_id', '=', 'detalle.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('devolucion.created_at', '>=', $inicioMes)
            ->selectRaw('COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle_devolucion.subtotal ELSE 0 END), 0) as ingresos_confirmados, COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle_devolucion.cantidad * detalle.costo_unitario ELSE 0 END), 0) as costos_confirmados, COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle_devolucion.subtotal ELSE 0 END), 0) as ingresos_estimados, COALESCE(SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle_devolucion.cantidad * producto.precio_compra ELSE 0 END), 0) as costos_estimados')
            ->first();

        $ingresosConfirmados = (float) $ventas->ingresos_confirmados - (float) $devoluciones->ingresos_confirmados;
        $costosConfirmados = (float) $ventas->costos_confirmados - (float) $devoluciones->costos_confirmados;
        $ingresosEstimados = (float) $ventas->ingresos_estimados - (float) $devoluciones->ingresos_estimados;
        $costosEstimados = (float) $ventas->costos_estimados - (float) $devoluciones->costos_estimados;

        return [
            'margen_confirmado' => $ingresosConfirmados - $costosConfirmados,
            'margen_estimado_historico' => $ingresosEstimados - $costosEstimados,
            'ingresos_confirmados' => $ingresosConfirmados,
            'ingresos_estimados_historico' => $ingresosEstimados,
        ];
    }

    private function productosRentablesDelMes(Carbon $inicioMes)
    {
        $ventas = DB::table('detalle_ventas as detalle')
            ->join('ventas as venta', 'detalle.venta_id', '=', 'venta.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('venta.estado', 'completada')
            ->where('venta.created_at', '>=', $inicioMes)
            ->groupBy('producto.id', 'producto.nombre', 'producto.imagen_url')
            ->selectRaw('
                producto.id,
                producto.nombre,
                producto.imagen_url,
                SUM(detalle.cantidad) as unidades,
                SUM(detalle.subtotal) as ingresos,
                SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle.subtotal - (detalle.cantidad * detalle.costo_unitario) ELSE 0 END) as margen_confirmado,
                SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle.subtotal - (detalle.cantidad * producto.precio_compra) ELSE 0 END) as margen_estimado_historico,
                SUM(CASE WHEN detalle.costo_unitario IS NULL THEN 1 ELSE 0 END) as lineas_estimadas
            ');

        $devoluciones = DB::table('detalle_devoluciones_venta as detalle_devolucion')
            ->join('devoluciones_venta as devolucion', 'detalle_devolucion.devolucion_venta_id', '=', 'devolucion.id')
            ->join('detalle_ventas as detalle', 'detalle_devolucion.detalle_venta_id', '=', 'detalle.id')
            ->join('productos as producto', 'detalle.producto_id', '=', 'producto.id')
            ->where('devolucion.created_at', '>=', $inicioMes)
            ->groupBy('detalle.producto_id')
            ->selectRaw('
                detalle.producto_id,
                SUM(detalle_devolucion.cantidad) as unidades,
                SUM(detalle_devolucion.subtotal) as ingresos,
                SUM(CASE WHEN detalle.costo_unitario IS NOT NULL THEN detalle_devolucion.subtotal - (detalle_devolucion.cantidad * detalle.costo_unitario) ELSE 0 END) as margen_confirmado,
                SUM(CASE WHEN detalle.costo_unitario IS NULL THEN detalle_devolucion.subtotal - (detalle_devolucion.cantidad * producto.precio_compra) ELSE 0 END) as margen_estimado_historico,
                SUM(CASE WHEN detalle.costo_unitario IS NULL THEN 1 ELSE 0 END) as lineas_estimadas
            ');

        return DB::query()
            ->fromSub($ventas, 'ventas')
            ->leftJoinSub($devoluciones, 'devoluciones', 'devoluciones.producto_id', '=', 'ventas.id')
            ->selectRaw('
                ventas.id,
                ventas.nombre,
                ventas.imagen_url,
                ventas.unidades - COALESCE(devoluciones.unidades, 0) as unidades,
                ventas.ingresos - COALESCE(devoluciones.ingresos, 0) as ingresos,
                ventas.margen_confirmado - COALESCE(devoluciones.margen_confirmado, 0) as margen_confirmado,
                ventas.margen_estimado_historico - COALESCE(devoluciones.margen_estimado_historico, 0) as margen_estimado_historico,
                ventas.lineas_estimadas - COALESCE(devoluciones.lineas_estimadas, 0) as lineas_estimadas
            ')
            ->whereRaw('(ventas.unidades - COALESCE(devoluciones.unidades, 0)) > 0')
            ->orderByDesc(DB::raw('(ventas.margen_confirmado - COALESCE(devoluciones.margen_confirmado, 0)) + (ventas.margen_estimado_historico - COALESCE(devoluciones.margen_estimado_historico, 0))'))
            ->limit(5)
            ->get();
    }


    private function filtrarVentas(Request $request)
    {
        $query = Venta::with(['cliente'])->withSum('devoluciones', 'total');

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('created_at', '>=', $request->fecha_inicio);
        }
        if ($request->filled('fecha_fin')) {
            $query->whereDate('created_at', '<=', $request->fecha_fin);
        }

        return $query->latest()->get()->each(function (Venta $venta) {
            $devuelto = $venta->estado === 'completada' ? (float) ($venta->devoluciones_sum_total ?? 0) : 0;
            $venta->setAttribute('devoluciones_total', $devuelto);
            $venta->setAttribute('total_neto', $venta->estado === 'completada' ? (float) $venta->total - $devuelto : 0);
        });
    }

    private function resumenComercial($ventas): array
    {
        $completadas = $ventas->where('estado', 'completada');

        return [
            'ventas_brutas' => (float) $completadas->sum('total'),
            'devoluciones' => (float) $completadas->sum('devoluciones_total'),
            'ventas_netas' => (float) $completadas->sum('total_neto'),
            'ventas_anuladas' => (int) $ventas->where('estado', 'anulada')->count(),
        ];
    }

    public function exportarPdf(Request $request)
    {
        $ventas = $this->filtrarVentas($request);
        $resumen = $this->resumenComercial($ventas);

        return Pdf::loadView('pdf.reporte_ventas', compact('ventas', 'resumen'))
            ->download('reporte_ventas_' . Carbon::now()->format('Ymd_His') . '.pdf');
    }

    public function exportarExcel(Request $request)
    {
        $ventas = $this->filtrarVentas($request);
        $resumen = $this->resumenComercial($ventas);
        $filename = 'reporte_ventas_' . Carbon::now()->format('Ymd_His') . '.csv';

        return response()->stream(function () use ($ventas, $resumen) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, ['ID Venta', 'Fecha', 'Cliente', 'Método Pago', 'Estado', 'Venta bruta (S/)', 'Devuelto (S/)', 'Venta neta (S/)']);

            foreach ($ventas as $venta) {
                fputcsv($file, [
                    $venta->id,
                    $venta->created_at->format('d/m/Y H:i'),
                    $venta->cliente->nombre_razon_social ?? $venta->cliente->nombre ?? 'Cliente eventual',
                    $venta->metodo_pago,
                    ucfirst($venta->estado ?? 'completada'),
                    number_format((float) $venta->total, 2),
                    number_format((float) $venta->devoluciones_total, 2),
                    number_format((float) $venta->total_neto, 2),
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, ['Resumen neto', '', '', '', '', number_format($resumen['ventas_brutas'], 2), number_format($resumen['devoluciones'], 2), number_format($resumen['ventas_netas'], 2)]);
            fputcsv($file, ['Ventas anuladas', $resumen['ventas_anuladas']]);
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
        $resumen = $this->resumenComercial($ventas);
        $pdf = Pdf::loadView('pdf.reporte_ventas', compact('ventas', 'resumen'));

        Mail::send([], [], function ($message) use ($request, $pdf) {
            $message->to($request->email)
                ->subject('Reporte de Ventas - Botica')
                ->html('Adjunto encontrarás el reporte de ventas generado en PDF.')
                ->attachData($pdf->output(), 'Reporte_Ventas.pdf', ['mime' => 'application/pdf']);
        });

        return response()->json(['message' => 'Reporte enviado con éxito.']);
    }
}
