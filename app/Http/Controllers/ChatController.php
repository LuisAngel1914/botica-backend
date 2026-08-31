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

        $mensajeMinuscula = strtolower($mensaje);

        // 1. Detección de preguntas ajenas a la botica o farmacia
        $palabrasProhibidas = [
            'departamento', 'departamentos', 'peru', 'perú', 'capital', 'clima', 
            'futbol', 'fútbol', 'musica', 'música', 'pelicula', 'película', 
            'politica', 'política', 'receta de cocina', 'historia'
        ];

        foreach ($palabrasProhibidas as $palabra) {
            if (str_contains($mensajeMinuscula, $palabra)) {
                return response()->json([
                    'respuesta' => '🤖 Lo siento, solo puedo responder preguntas relacionadas con la botica, medicamentos, productos en stock, precios y consultas farmacéuticas.'
                ], 200);
            }
        }

        // 2. Detección de intenciones para listar todo el inventario/stock general
        $frasesGenerales = ['productos disponibles', 'que productos hay', 'qué productos hay', 'catalogo', 'catálogo', 'stock', 'lista', 'inventario', 'productos'];
        $esConsultaGeneral = false;
        foreach ($frasesGenerales as $frase) {
            if (str_contains($mensajeMinuscula, $frase)) {
                $esConsultaGeneral = true;
                break;
            }
        }

        if ($esConsultaGeneral && !str_contains($mensajeMinuscula, 'hay ') && !str_contains($mensajeMinuscula, 'tienen ')) {
            $todosProds = Producto::take(10)->get();
            if ($todosProds->count() > 0) {
                $txt = "🤖 **Productos disponibles en el sistema:**\n\n";
                foreach ($todosProds as $prod) {
                    $stk = $prod->stock_actual ?? $prod->stock_total ?? $prod->stock ?? 0;
                    $prc = $prod->precio ?? $prod->precio_venta ?? 0;
                    $txt .= "• **{$prod->nombre}** | Stock: {$stk} unidades | Precio: S/ " . number_format((float)$prc, 2, '.', '') . "\n";
                }
                return response()->json(['respuesta' => $txt], 200);
            }
        }

        // 3. Extraer palabras clave de búsqueda
        $palabrasOmitir = ['hay', 'productos', 'producto', 'disponibles', 'disponible', 'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'cuanto', 'cuánto', 'cuesta', 'precio', 'tienen', 'tiene', 'sobre', 'que', 'qué', 'en', 'para', 'saber', 'si', 'busco', 'con', 'por', '?'];
        
        $tokens = explode(' ', preg_replace('/[^\w\s]/u', '', $mensajeMinuscula));
        $palabrasClave = array_filter($tokens, function($token) use ($palabrasOmitir) {
            return !in_array($token, $palabrasOmitir) && strlen($token) > 2;
        });

        // 4. Buscar productos por coincidencia de palabras clave
        $query = Producto::query();
        if (!empty($palabrasClave)) {
            $query->where(function($q) use ($palabrasClave) {
                foreach ($palabrasClave as $palabra) {
                    $q->orWhereRaw('LOWER(nombre) LIKE ?', ["%{$palabra}%"])
                      ->orWhere('codigo_barras', 'LIKE', "%{$palabra}%");
                }
            });
        }

        $productosEncontrados = $query->take(5)->get();

        // Si no encontró por palabra clave pero la pregunta era abierta, obtener el catálogo base
        if ($productosEncontrados->count() === 0 && (str_contains($mensajeMinuscula, 'producto') || str_contains($mensajeMinuscula, 'hay'))) {
            $productosEncontrados = Producto::take(5)->get();
        }

        // 5. Intento de respuesta con la API de Gemini (si hay API KEY configurada)
        $apiKey = env('GEMINI_API_KEY');
        if ($apiKey) {
            try {
                $catalogo = Producto::select('nombre', 'precio', 'precio_venta', 'stock', 'stock_actual')->take(20)->get()->toJson();
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

                $response = Http::withoutVerifying()->timeout(8)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Eres un asistente farmacéutico exclusivo de esta botica. Responde SOLAMENTE temas sobre medicamentos, salud y productos. Productos disponibles en sistema: {$catalogo}. Consulta: {$mensaje}"]
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
                // Fallback automático al motor local
            }
        }

        // 6. Respuesta del Motor Local
        if ($productosEncontrados->count() > 0) {
            $txt = "🤖 **Productos encontrados en el sistema:**\n\n";
            foreach ($productosEncontrados as $prod) {
                $stockVal = $prod->stock_actual ?? $prod->stock_total ?? $prod->stock ?? 0;
                $precioVal = $prod->precio ?? $prod->precio_venta ?? 0;
                $precioFormateado = number_format((float)$precioVal, 2, '.', '');

                $txt .= "• **{$prod->nombre}** | Stock: {$stockVal} unidades | Precio: S/ {$precioFormateado}\n";
            }
            return response()->json(['respuesta' => $txt], 200);
        }

        return response()->json([
            'respuesta' => "🤖 No encontré ningún producto registrado en el sistema que coincida con tu búsqueda. Intenta consultando por el nombre del medicamento o su principio activo."
        ], 200);
    }
}