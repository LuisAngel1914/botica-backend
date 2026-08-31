<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ConsultaDocumentoService
{
    public function consultarDni($dni)
    {
        try {
            $token = env('APIS_NET_PE_TOKEN');

            // Intento 1: API Decolecta / apis.net.pe con token en la URL
            $response = Http::acceptJson()
                ->get("https://api.apis.net.pe/v1/dni?numero={$dni}&token={$token}");

            if ($response->failed()) {
                // Intento 2: API v2 con Bearer Token
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->get("https://api.apis.net.pe/v2/reniec/dni?numero={$dni}");
            }

            if ($response->successful()) {
                $data = $response->json();
                
                $nombreCompleto = $data['nombre'] 
                    ?? $data['nombreCompleto'] 
                    ?? trim(($data['nombres'] ?? '') . ' ' . ($data['apellidoPaterno'] ?? '') . ' ' . ($data['apellidoMaterno'] ?? ''));

                return [
                    'data' => [
                        'nombre_completo' => $nombreCompleto,
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

            $response = Http::acceptJson()
                ->get("https://api.apis.net.pe/v1/ruc?numero={$ruc}&token={$token}");

            if ($response->failed()) {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->get("https://api.apis.net.pe/v2/sunat/ruc?numero={$ruc}");
            }

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'data' => [
                        'nombre_o_razon_social' => $data['nombre'] ?? $data['razonSocial'] ?? '',
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