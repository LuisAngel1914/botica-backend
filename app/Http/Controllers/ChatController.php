<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Producto;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function responder(Request $request)
    {
        $mensaje = trim($request->input('mensaje', ''));

        if (empty($mensaje)) {
            return response()->json([
                'respuesta' => 'Por favor escribe una consulta o pregunta sobre algún producto.'
            ], 200);
        }

        // Búsqueda directa en la base de datos según lo que consulte el usuario
        $busqueda = strtolower($mensaje);
        $productosEncontrados = Producto::whereRaw('LOWER(nombre) LIKE ?', ["%{$busqueda}%"])
            ->orWhere('codigo_barras', 'LIKE', "%{$busqueda}%")
            ->take(5)
            ->get();

        // Obtener la clave de API desde .env si existe
        $apiKey = env('GEMINI_API_KEY');

        // Si existe la clave de Gemini, intentamos consultar a la IA
        if ($apiKey) {
            try {
                $catalogo = Producto::select('nombre', 'precio', 'stock')->take(20)->get()->toJson();
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

                $response = Http::withoutVerifying()->timeout(10)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Eres un asistente farmacéutico del POS. Productos en sistema: {$catalogo}. Consulta del usuario: {$mensaje}"]
                            ]
                        ]
                    ]
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $textoIa = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if ($textoIa) {
                        return response()->json(['respuesta' => $textoIa], 200);
                    }
                }
            } catch (\Throwable $e) {
                // Si falla la API externa, cae suavemente a la búsqueda local
            }
        }

        // Respondedor Local Automático (Si no hay API KEY o si falla Gemini)
        if ($productosEncontrados->count() > 0) {
            $txt = "🤖 **Resultados encontrados en tu sistema:**\n\n";
            foreach ($productosEncontrados as $prod) {
                $txt .= "• **{$prod->nombre}** | Stock: {$prod->stock} unidades | Precio: S/ {$prod->precio}\n";
            }
            return response()->json(['respuesta' => $txt], 200);
        }

        // Si no se encontró nada por nombre, mostrar stock general disponible
        $todos = Producto::take(5)->get();
        if ($todos->count() > 0) {
            $txt = "🤖 No encontré coincidencias exactas para \"{$mensaje}\", pero aquí tienes algunos productos disponibles en el POS:\n\n";
            foreach ($todos as $prod) {
                $txt .= "• **{$prod->nombre}** | Stock: {$prod->stock} | Precio: S/ {$prod->precio}\n";
            }
            return response()->json(['respuesta' => $txt], 200);
        }

        return response()->json([
            'respuesta' => "🤖 Hola. No tengo productos registrados en la base de datos para mostrarte en este momento."
        ], 200);
    }
}