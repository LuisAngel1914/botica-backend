<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class ConfiguracionController extends Controller
{
    public function publica()
    {
        return response()->json(Configuracion::actual()->publicData());
    }

    public function show()
    {
        return response()->json(Configuracion::actual()->publicData());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'nombre_comercial' => 'required|string|max:120',
            'razon_social' => 'nullable|string|max:180',
            'ruc' => ['nullable', 'regex:/^\d{11}$/'],
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'logo_url' => 'nullable|url|max:2048',
            'moneda' => 'required|in:PEN,USD',
            'simbolo_moneda' => 'required|string|max:5',
            'impuesto_nombre' => 'required|string|max:20',
            'impuesto_porcentaje' => 'required|numeric|min:0|max:100',
            'serie_comprobante' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
            'stock_minimo_default' => 'required|integer|min:0|max:999999',
            'dias_alerta_vencimiento' => 'required|integer|min:1|max:365',
            'mensaje_ticket' => 'required|string|max:255',
        ], [
            'ruc.regex' => 'El RUC debe contener exactamente 11 dígitos.',
            'serie_comprobante.regex' => 'La serie solo puede contener letras mayúsculas, números y guiones.',
        ]);

        $configuracion = Configuracion::actual();
        $configuracion->fill($data);
        $changedFields = array_keys($configuracion->getDirty());
        $configuracion->save();

        ActivityLogger::log($request, 'business.settings_updated', Configuracion::class, $configuracion->id, [
            'campos_modificados' => $changedFields,
            'configuracion_completa' => $configuracion->missingRequiredFields() === [],
        ]);

        return response()->json([
            'message' => 'Configuración guardada correctamente.',
            'configuracion' => $configuracion->publicData(),
        ]);
    }
}
