<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Producto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Maneja las consultas del chat de la IA.
     */
    public function responder(Request $request)
    {
        try {
            $mensaje = $request->input('mensaje', '');

            if (empty(trim($mensaje))) {
                return response()->json([
                    'respuesta' => 'Por favor escribe una consulta o pregunta sobre algún producto.'
                ], 200);
            }

            // 1. Obtener catálogo básico para dar contexto a la IA
            $productos = Producto::select('nombre', 'precio', 'stock', 'presentacion')
                ->take(30)
                ->get()
                ->toArray();

            $contextoProductos = json_encode($productos, JSON_UNESCAPED_UNICODE);

            // 2. Clave de la API (desde .env)
            $apiKey = config('services.gemini.key') ?? env('GEMINI_API_KEY');

            if (!$apiKey) {
                // Fallback interno si no hay clave de API configurada
                return response()->json([
                    'respuesta' => "🤖 Asistente POS:\nRecibí tu consulta sobre: \"{$mensaje}\".\nActualmente el servicio de IA avanzada no tiene configurada la API KEY, pero puedes revisar el inventario directamente en el buscador del POS."
                ], 200);
            }

            // 3. Llamada a la API de Gemini
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

            $systemPrompt = "Eres un asistente farmacéutico experto dentro de un sistema POS de botica. Responde de forma concisa, profesional y amable en español. Aquí tienes el inventario disponible: {$contextoProductos}";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemPrompt . "\n\nConsulta del usuario: " . $mensaje]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $textoIa = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'No pude procesar la respuesta.';

                return response()->json([
                    'respuesta' => $textoIa
                ], 200);
            }

            // Si falla la API externa
            Log::error('Error Gemini API: ' . $response->body());
            return response()->json([
                'respuesta' => "🤖 Asistente POS:\nConsulté el sistema pero hubo una falla temporal con el servicio de IA externa. Reintenta en unos instantes."
            ], 200);

        } catch (\Exception $e) {
            Log::error('Excepción en ChatController: ' . $e->getMessage());
            
            return response()->json([
                'respuesta' => "⚠️ Ocurrió un inconveniente al procesar tu mensaje. El servidor está operativo pero no pudo procesar la solicitud."
            ], 200);
        }
    }
}