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

        // 1. Identificar intenciones abiertas de consultar el catálogo / stock general
        $palabrasGenerales = ['producto', 'productos', 'catalogo', 'catálogo', 'inventario', 'stock', 'lista', 'disponible', 'disponibles', 'tienen', 'hay'];
        
        // Limpiar signos de puntuación
        $textoLimpio = trim(preg_replace('/[^\w\s]/u', '', $mensajeMinuscula));
        $tokensTexto = array_filter(explode(' ', $textoLimpio));

        // Verificar si la consulta contiene únicamente palabras de solicitud general
        $esSolicitudCatalogo = false;
        if (!empty($tokensTexto)) {
            $coincidenciasGenerales = 0;
            foreach ($tokensTexto as $token) {
                if (in_array($token, $palabrasGenerales) || in_array($token, ['que', 'qué', 'cuantod', 'cuantos', 'cuántos', 'ver', 'mostrar'])) {
                    $coincidenciasGenerales++;
                }
            }
            // Si todas o la mayoría de las palabras son genéricas de solicitud
            if ($coincidenciasGenerales >= count($tokensTexto) || str_contains($mensajeMinuscula, 'productos disponibles') || str_contains($mensajeMinuscula, 'que productos')) {
                $esSolicitudCatalogo = true;
            }
        }

        // Si el usuario pide ver el catálogo general
        if ($esSolicitudCatalogo) {
            $productos = Producto::take(10)->get();
            if ($productos->count() > 0) {
                $txt = "🤖 **Productos disponibles en el sistema:**\n\n";
                foreach ($productos as $prod) {
                    $stockVal = $prod->stock_actual ?? $prod->stock_total ?? $prod->stock ?? 0;
                    $precioVal = $prod->precio ?? $prod->precio_venta ?? 0;
                    $precioFormateado = number_format((float)$precioVal, 2, '.', '');

                    $txt .= "• **{$prod->nombre}** | Stock: {$stockVal} unidades | Precio: S/ {$precioFormateado}\n";
                }
                return response()->json(['respuesta' => $txt], 200);
            }
        }

        // 2. Extraer palabras clave especificas para buscar medicamentos concretos
        $palabrasOmitir = [
            'hay', 'productos', 'producto', 'disponibles', 'disponible', 'de', 'del', 
            'la', 'el', 'los', 'las', 'un', 'una', 'cuanto', 'cuánto', 'cuesta', 'precio', 
            'tienen', 'tiene', 'sobre', 'que', 'qué', 'en', 'para', 'saber', 'si', 'busco', 
            'con', 'por', 'cuales', 'cuáles', 'cuantos', 'cuántos', 'existen', 'años', 'tienes'
        ];

        $palabrasClave = array_filter($tokensTexto, function($token) use ($palabrasOmitir) {
            return !in_array($token, $palabrasOmitir) && strlen($token) > 2;
        });

        // 3. Búsqueda específica en base de datos por término
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

        // 4. Validar pertenencia al ámbito farmacéutico
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

        // Si no hay productos ni términos farmacéuticos -> Rechazar pregunta ajena
        if ($productosEncontrados->count() === 0 && !$esConsultaFarmaceutica) {
            return response()->json([
                'respuesta' => $respuestaGenericaRechazo
            ], 200);
        }

        // 5. Integración con Gemini IA (si API KEY está configurada)
        $apiKey = env('GEMINI_API_KEY');
        if ($apiKey) {
            try {
                $catalogo = Producto::select('nombre', 'precio', 'precio_venta', 'stock', 'stock_actual')->take(20)->get()->toJson();
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

                $response = Http::withoutVerifying()->timeout(8)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Eres un asistente farmacéutico exclusivo de esta botica. Responde ÚNICAMENTE sobre medicamentos, salud, dosis o productos. Si la pregunta es ajena a la botica o medicina, responde exactamente: '{$respuestaGenericaRechazo}'. Productos en sistema: {$catalogo}. Consulta: {$mensaje}"]
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
                // Fallback automático
            }
        }

        // 6. Si hubo hallazgos en la búsqueda de productos
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

        // 7. Si fue una pregunta farmacéutica válida pero el producto no está registrado
        return response()->json([
            'respuesta' => "🤖 No encontré ningún producto registrado en el sistema que coincida con tu búsqueda. Intenta consultando por el nombre del medicamento o su principio activo."
        ], 200);
    }
}