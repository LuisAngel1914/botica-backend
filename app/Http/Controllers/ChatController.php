<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Producto;

class ChatController extends Controller
{
    public function responder(Request $request)
    {
        $request->validate([
            'mensaje' => 'required|string|max:1000',
        ]);

        $apiKey = env('GEMINI_API_KEY');
        if (!$apiKey) {
            return response()->json(['message' => 'API Key de IA no configurada.'], 500);
        }

        // Contexto de catálogo para el asistente
        $productosCatalog = Producto::select('nombre', 'precio_venta', 'stock_actual', 'requiere_receta')
            ->get()
            ->take(100)
            ->toJson();

        $systemPrompt = "Eres un Asistente Farmacéutico Virtual experto para el POS de la botica. " .
            "Sugiérenos medicamentos genéricos o alternativas según principio activo, posología básica y advertencias. " .
            "Catálogo actual de la botica: " . $productosCatalog;

        $userMessage = $request->input('mensaje');

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

            $response = Http::post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemPrompt . "\n\nConsulta del cajero: " . $userMessage]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                $urlFallback = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";
                $response = Http::post($urlFallback, [
                    'contents' => [
                        ['parts' => [['text' => $systemPrompt . "\n\nConsulta del cajero: " . $userMessage]]]
                    ]
                ]);
            }

            if ($response->successful()) {
                $data = $response->json();
                $respuestaIA = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'No se obtuvo respuesta.';
                return response()->json(['respuesta' => $respuestaIA]);
            }

            return response()->json([
                'message' => 'Error al consultar Gemini',
                'error' => $response->body()
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }
}