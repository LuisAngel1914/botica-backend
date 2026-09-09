<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Caja;
use App\Models\Venta;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\ActivityLogger;
use Carbon\Carbon;

class CajaController extends Controller
{
    public function estadoActual()
    {
        $caja = Caja::where('estado', 'abierta')->latest()->first();

        if (!$caja) {
            return response()->json([
                'estado' => 'cerrada',
                'mensaje' => 'No hay ninguna caja abierta actualmente.',
                'monto_inicial' => 0,
                'ventas_efectivo' => 0,
                'ventas_digital' => 0,
                'total_esperado' => 0,
            ]);
        }

        $fechaInicio = $caja->fecha_apertura ?? $caja->created_at;
        $ventasEfectivo = Venta::where('created_at', '>=', $fechaInicio)->where('metodo_pago', 'Efectivo')->where(fn ($query) => $query->where('estado', 'completada')->orWhereNull('estado'))->sum('total');
        $ventasDigitales = Venta::where('created_at', '>=', $fechaInicio)->where('metodo_pago', '!=', 'Efectivo')->where(fn ($query) => $query->where('estado', 'completada')->orWhereNull('estado'))->sum('total');

        $montoInicial = (float) $caja->monto_inicial;
        $totalEfectivo = (float) $ventasEfectivo;

        return response()->json([
            'estado' => 'abierta',
            'caja_id' => $caja->id,
            'caja' => $caja,
            'fecha_apertura' => Carbon::parse($fechaInicio)->format('d/m/Y, h:i a'),
            'monto_inicial' => $montoInicial,
            'ventas_efectivo' => $totalEfectivo,
            'ventas_digital' => (float) $ventasDigitales,
            'ventas_digitales' => (float) $ventasDigitales,
            'total_esperado' => $montoInicial + $totalEfectivo,
            'monto_esperado' => $montoInicial + $totalEfectivo,
        ]);
    }

    public function ultimoCierre()
    {
        $caja = Caja::with(['correcciones' => fn ($query) => $query->latest(), 'correcciones.user'])
            ->where('estado', 'cerrada')
            ->latest('fecha_cierre')
            ->first();

        if (!$caja) {
            return response()->json(['message' => 'No hay cierres registrados.'], 404);
        }

        return response()->json($caja);
    }

    public function abrir(Request $request)
    {
        $cajaAbierta = Caja::where('estado', 'abierta')->first();
        if ($cajaAbierta) {
            return response()->json(['message' => 'Ya existe una caja abierta.'], 400);
        }

        $request->validate(['monto_inicial' => 'required|numeric|min:0']);
        $usuarioId = auth()->id() ?? User::value('id') ?? DB::table('users')->value('id');

        if (!$usuarioId) {
            return response()->json(['message' => 'Error: Debe existir al menos un usuario en el sistema para abrir caja.'], 400);
        }

        $caja = Caja::create([
            'usuario_id' => $usuarioId,
            'monto_inicial' => $request->monto_inicial,
            'estado' => 'abierta',
            'fecha_apertura' => Carbon::now(),
        ]);

        ActivityLogger::log($request, 'cash_register.opened', Caja::class, $caja->id, ['monto_inicial' => (float) $caja->monto_inicial]);

        return response()->json(['message' => 'Caja abierta correctamente.', 'caja' => $caja], 201);
    }

    public function cerrar(Request $request)
    {
        $caja = Caja::where('estado', 'abierta')->latest()->first();
        if (!$caja) {
            return response()->json(['message' => 'No hay caja abierta para cerrar.'], 400);
        }

        $request->validate(['monto_final' => 'required|numeric|min:0']);
        $fechaInicio = $caja->fecha_apertura ?? $caja->created_at;
        $ventasEfectivo = Venta::where('created_at', '>=', $fechaInicio)->where('metodo_pago', 'Efectivo')->where(fn ($query) => $query->where('estado', 'completada')->orWhereNull('estado'))->sum('total');
        $ventasDigitales = Venta::where('created_at', '>=', $fechaInicio)->where('metodo_pago', '!=', 'Efectivo')->where(fn ($query) => $query->where('estado', 'completada')->orWhereNull('estado'))->sum('total');

        $montoEsperado = (float) $caja->monto_inicial + (float) $ventasEfectivo;
        $diferencia = (float) $request->monto_final - $montoEsperado;

        $caja->update([
            'monto_final' => $request->monto_final,
            'total_ventas_efectivo' => $ventasEfectivo,
            'diferencia' => $diferencia,
            'estado' => 'cerrada',
            'fecha_cierre' => Carbon::now(),
        ]);

        ActivityLogger::log($request, 'cash_register.closed', Caja::class, $caja->id, ['monto_final' => (float) $request->monto_final, 'diferencia' => $diferencia]);

        return response()->json([
            'message' => 'Caja cerrada exitosamente.',
            'resumen' => [
                'monto_inicial' => (float) $caja->monto_inicial,
                'ventas_efectivo' => (float) $ventasEfectivo,
                'ventas_digitales' => (float) $ventasDigitales,
                'monto_esperado' => $montoEsperado,
                'monto_real' => (float) $request->monto_final,
                'diferencia' => $diferencia,
            ],
        ]);
    }
}