<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\ConsultaDocumentoService;
use Illuminate\Http\Request;
use Exception;

class ClienteController extends Controller
{
    protected $consultaService;

    public function __construct(ConsultaDocumentoService $consultaService)
    {
        $this->consultaService = $consultaService;
    }

    public function index()
    {
        return response()->json(Cliente::all(), 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tipo_documento'      => 'required|in:DNI,RUC,CE',
            'numero_documento'    => 'required|string|unique:clientes,numero_documento',
            'nombre_razon_social' => 'required|string|max:255',
            'direccion'           => 'nullable|string',
            'telefono'            => 'nullable|string|max:15',
            'email'               => 'nullable|email',
        ]);

        $cliente = Cliente::create($validated);

        return response()->json([
            'message' => 'Cliente registrado con éxito',
            'data'    => $cliente
        ], 201);
    }

    // Nombre idéntico al definido en la ruta: /clientes/buscar/{doc}
    public function buscarPorDocumento($doc)
    {
        try {
            // 1. Buscar en BD local primero
            $clienteLocal = Cliente::where('numero_documento', $doc)->first();

            if ($clienteLocal) {
                return response()->json([
                    'origen' => 'local',
                    'data'   => $clienteLocal
                ], 200);
            }

            // 2. Si no existe en la BD local, intentar API externa por longitud
            if (strlen($doc) === 8) {
                $apiData = $this->consultaService->consultarDni($doc);
                if ($apiData && isset($apiData['data'])) {
                    $d = $apiData['data'];
                    return response()->json([
                        'origen' => 'reniec',
                        'data'   => [
                            'tipo_documento'      => 'DNI',
                            'numero_documento'    => $doc,
                            'nombre_razon_social' => $d['nombre_completo'] ?? trim(($d['nombres'] ?? '') . ' ' . ($d['apellido_paterno'] ?? '') . ' ' . ($d['apellido_materno'] ?? '')),
                            'direccion'           => $d['direccion'] ?? null,
                        ]
                    ], 200);
                }
            } elseif (strlen($doc) === 11) {
                $apiData = $this->consultaService->consultarRuc($doc);
                if ($apiData && isset($apiData['data'])) {
                    $d = $apiData['data'];
                    return response()->json([
                        'origen' => 'sunat',
                        'data'   => [
                            'tipo_documento'      => 'RUC',
                            'numero_documento'    => $doc,
                            'nombre_razon_social' => $d['nombre_o_razon_social'] ?? '',
                            'direccion'           => $d['direccion'] ?? null,
                        ]
                    ], 200);
                }
            }

            return response()->json([
                'message' => 'Documento no encontrado en la base de datos ni en el servicio externo.'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'error'   => 'Error en la consulta',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}