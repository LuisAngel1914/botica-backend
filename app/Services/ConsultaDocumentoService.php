<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ConsultaDocumentoService
{
    public function consultarDni($dni)
    {
        try {
            $response = Http::get("https://api.apis.net.pe/v1/dni?numero={$dni}");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'data' => [
                        'nombre_completo' => $data['nombre'] ?? '',
                        'direccion'       => $data['direccion'] ?? null,
                    ]
                ];
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    public function consultarRuc($ruc)
    {
        try {
            $response = Http::get("https://api.apis.net.pe/v1/ruc?numero={$ruc}");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'data' => [
                        'nombre_o_razon_social' => $data['nombre'] ?? '',
                        'direccion'             => $data['direccion'] ?? null,
                    ]
                ];
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }
}