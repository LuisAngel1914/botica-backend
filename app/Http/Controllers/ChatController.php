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
        $respuestaGenericaRechazo = '🤖 Lo siento, solo puedo responder preguntas relacionadas con la botica, medicamentos, productos en stock, precios y consultas farmacéuticas.';

        // 1. Palabras clave explícitamente farmacéuticas/botica
        $terminosFarmaceuticos = [
            'medicamento', 'medicamentos', 'producto', 'productos', 'precio', 'precios', 
            'cuesta', 'costo', 'stock', 'inventario', 'botica', 'farmacia', 'pastilla', 
            'pastillas', 'jarabe', 'ampolla', 'tableta', 'tabletas', 'dosis', 'tratamiento', 
            'receta', 'equivalente', 'alternativa', 'sintoma', 'síntoma', 'dolor', 'vender', 
            'venta', 'comprar', 'disponible', 'disponibles', 'habrá', 'tienen', 'hay'
        ];

        $esConsultaFarmaceutica = false;
        foreach ($terminosFarmaceuticos as $termino) {
            if (str_contains($mensajeMinuscula, $termino)) {
                $esConsultaFarmaceutica = true;
                break;
            }
        }

        // 2. Extraer tokens para buscar en BD
        $palabrasOmitir = ['hay', 'productos', 'producto', 'disponibles', 'disponible', 'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'cuanto', 'cuánto', 'cuesta', 'precio', 'tienen', 'tiene', 'sobre', 'que', 'qué', 'en', 'para', 'saber', 'si', 'busco', 'con', 'por', 'cuales', 'cuáles', 'cuantos', 'cuántos', 'existen', '?'];
        
        $tokens = explode(' ', preg_replace('/[^\w\s]/u', '', $mensajeMinuscula));
        $palabrasClave = array_filter($tokens, function($token) use ($palabrasOmitir) {
            return !in_array($token, $palabrasOmitir) && strlen($token) > 2;
        });

        // 3. Consulta a la base de datos de productos
        $productosEncontrados = collect();
        if (!empty($palabrasClave)) {
            $query = Producto::query();
            $query->where(function($q) use ($palabrasClave) {
                foreach ($palabrasClave as $palabra) {
                    $q->orWhereRaw('LOWER(nombre) LIKE ?', ["%{$palabra}%"])
                      ->orWhere('codigo_barras', 'LIKE', "%{$palabra}%");
                }
            });
            $productosEncontrados = $query->take(5)->get();
        }

        // 4. Si NO encontró productos y la pregunta NO es sobre temas farmacéuticos -> Rechazar uniformemente
        if ($productosEncontrados->count() === 0 && !$esConsultaFarmaceutica) {
            return response()->json([
                'respuesta' => $respuestaGenericaRechazo
            ], 200);
        }

        // 5. Intento con Gemini IA (Si existe API KEY)
        $apiKey = env('GEMINI_API_KEY');
        if ($apiKey) {
            try {
                $catalogo = Producto::select('nombre', 'precio', 'precio_venta', 'stock', 'stock_actual')->take(20)->get()->toJson();
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

                $response = Http::withoutVerifying()->timeout(8)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Eres un asistente farmacéutico exclusivo de esta botica. Responde ÚNICAMENTE consultas sobre medicamentos, salud, dosis o productos. Si la pregunta es ajena a la botica o salud, responde exactamente: '{$respuestaGenericaRechazo}'. Catálogo disponible: {$catalogo}. Consulta: {$mensaje}"]
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
                // Caída al motor local
            }
        }

        // 6. Motor Local: Si se encontraron productos en la base de datos
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

        // 7. Si era una consulta de botica pero el producto no existe en BD
        return response()->json([
            'respuesta' => "🤖 No encontré ningún producto registrado en el sistema que coincida con tu búsqueda. Intenta consultando por el nombre del medicamento o su principio activo."
        ], 200);
    }
}