<?php
namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\CashClosureCorrection;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class CashClosureCorrectionController extends Controller
{
    public function store(Request $request, Caja $caja)
    {
        if ($caja->estado !== 'cerrada') {
            return response()->json(['message' => 'Solo se pueden corregir cierres ya realizados.'], 422);
        }

        $data = $request->validate([
            'monto_final_corregido' => 'required|numeric|min:0',
            'motivo' => 'required|string|min:10|max:1000',
        ]);

        $correction = CashClosureCorrection::create([
            'caja_id' => $caja->id,
            'user_id' => $request->user()->id,
            'monto_final_corregido' => $data['monto_final_corregido'],
            'motivo' => $data['motivo'],
        ]);

        ActivityLogger::log($request, 'cash_register.closure_corrected', Caja::class, $caja->id, [
            'monto_original' => (float) $caja->monto_final,
            'monto_corregido' => (float) $correction->monto_final_corregido,
            'motivo' => $data['motivo'],
        ]);

        return response()->json([
            'message' => 'Corrección registrada sin modificar el cierre original.',
            'correction' => $correction,
        ], 201);
    }
}