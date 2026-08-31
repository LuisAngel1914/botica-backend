<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ConsultaDocumentoService
{
    public function consultarDni($dni)
    {
        try {
            $token = env('APIS_NET_PE_TOKEN');

            // Petición a la v2 con Bearer Token de Decolecta / apis.net.pe
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ])->get("https://api.apis.net.pe/v2/reniec/dni?numero={$dni}");

            if ($response->successful()) {
                $data = $response->json();
                
                $nombre = $data['nombreCompleto'] 
                    ?? trim(($data['nombres'] ?? '') . ' ' . ($data['apellidoPaterno'] ?? '') . ' ' . ($data['apellidoMaterno'] ?? ''));

                return [
                    'data' => [
                        'nombre_completo' => $nombre ?: ($data['nombre'] ?? ''),
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
            $token = env('APIS_NET_PE_TOKEN');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ])->get("https://api.apis.net.pe/v2/sunat/ruc?numero={$ruc}");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'data' => [
                        'nombre_o_razon_social' => $data['razonSocial'] ?? $data['nombre'] ?? '',
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