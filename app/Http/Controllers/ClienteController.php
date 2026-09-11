<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\ConsultaDocumentoService;
use Illuminate\Http\Request;
use Exception;

class ClienteController extends Controller
{
    public function __construct(protected ConsultaDocumentoService $consultaService)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('q', ''));

        $clientes = Cliente::query()
            ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search) {
                $builder->where('nombre_razon_social', 'like', "%{$search}%")
                    ->orWhere('numero_documento', 'like', "%{$search}%")
                    ->orWhere('telefono', 'like', "%{$search}%");
            }))
            ->withCount(['ventas as compras_completadas' => fn ($query) => $query->where('estado', 'completada')])
            ->withSum(['ventas as gasto_total' => fn ($query) => $query->where('estado', 'completada')], 'total')
            ->orderByDesc('gasto_total')
            ->paginate($request->integer('per_page', 25));

        return response()->json($clientes);
    }

    public function resumen(Cliente $cliente)
    {
        $ventas = $cliente->ventas()
            ->with('detalles.producto:id,nombre')
            ->where('estado', 'completada')
            ->latest()
            ->take(20)
            ->get();

        return response()->json([
            'cliente' => $cliente,
            'metricas' => [
                'compras_completadas' => $cliente->ventas()->where('estado', 'completada')->count(),
                'gasto_total' => (float) $cliente->ventas()->where('estado', 'completada')->sum('total'),
                'ticket_promedio' => (float) $cliente->ventas()->where('estado', 'completada')->avg('total'),
                'ultima_compra' => optional($ventas->first())->created_at,
            ],
            'ventas' => $ventas,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tipo_documento' => 'required|in:DNI,RUC,CE',
            'numero_documento' => 'required|string|unique:clientes,numero_documento',
            'nombre_razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string|max:15',
            'email' => 'nullable|email',
        ]);

        $cliente = Cliente::create($validated);
        ActivityLogger::log($request, 'customer.created', Cliente::class, $cliente->id, ['tipo_documento' => $cliente->tipo_documento]);

        return response()->json(['message' => 'Cliente registrado con éxito', 'data' => $cliente], 201);
    }

    public function update(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'tipo_documento' => 'required|in:DNI,RUC,CE',
            'numero_documento' => 'required|string|unique:clientes,numero_documento,' . $cliente->id,
            'nombre_razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string|max:15',
            'email' => 'nullable|email',
        ]);

        $cliente->update($validated);
        ActivityLogger::log($request, 'customer.updated', Cliente::class, $cliente->id, ['tipo_documento' => $cliente->tipo_documento]);

        return response()->json(['message' => 'Cliente actualizado con éxito', 'data' => $cliente]);
    }

    public function buscarPorDocumento($doc)
    {
        try {
            $clienteLocal = Cliente::where('numero_documento', $doc)->first();

            if ($clienteLocal) {
                return response()->json(['origen' => 'local', 'data' => $clienteLocal]);
            }

            if (strlen($doc) === 8) {
                $apiData = $this->consultaService->consultarDni($doc);
                if ($apiData && isset($apiData['data'])) {
                    $data = $apiData['data'];
                    return response()->json(['origen' => 'reniec', 'data' => [
                        'tipo_documento' => 'DNI',
                        'numero_documento' => $doc,
                        'nombre_razon_social' => $data['nombre_completo'] ?? trim(($data['nombres'] ?? '') . ' ' . ($data['apellido_paterno'] ?? '') . ' ' . ($data['apellido_materno'] ?? '')),
                        'direccion' => $data['direccion'] ?? null,
                    ]]);
                }
            } elseif (strlen($doc) === 11) {
                $apiData = $this->consultaService->consultarRuc($doc);
                if ($apiData && isset($apiData['data'])) {
                    $data = $apiData['data'];
                    return response()->json(['origen' => 'sunat', 'data' => [
                        'tipo_documento' => 'RUC',
                        'numero_documento' => $doc,
                        'nombre_razon_social' => $data['nombre_o_razon_social'] ?? '',
                        'direccion' => $data['direccion'] ?? null,
                    ]]);
                }
            }

            return response()->json(['message' => 'Documento no encontrado en la base de datos ni en el servicio externo.'], 404);
        } catch (Exception $exception) {
            return response()->json(['error' => 'Error en la consulta', 'message' => $exception->getMessage()], 500);
        }
    }
}