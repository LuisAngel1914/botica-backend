<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Mail\ReporteDiarioMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class ReporteMailController extends Controller
{
    public function enviarReporte(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $hoy = Carbon::today();

        $ventasHoy = Venta::with('cliente')
            ->whereDate('created_at', $hoy)
            ->where('estado', 'completada')
            ->get();

        $datosReporte = [
            'total_general'   => $ventasHoy->sum('total'),
            'cantidad_ventas' => $ventasHoy->count(),
            'ventas'          => $ventasHoy
        ];

        Mail::to($request->email)->send(new ReporteDiarioMail($datosReporte));

        return response()->json([
            'message' => 'Reporte enviado con éxito a ' . $request->email
        ], 200);
    }
}