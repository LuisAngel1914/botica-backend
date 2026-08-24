<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ReporteMailController extends Controller
{
    public function enviarReporte(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'asunto' => 'required|string',
            'mensaje' => 'required|string',
        ]);

        try {
            Mail::raw($request->mensaje, function ($message) use ($request) {
                $message->to($request->email)
                        ->subject($request->asunto);
            });

            return response()->json(['status' => 'success', 'message' => 'Reporte enviado con éxito.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}