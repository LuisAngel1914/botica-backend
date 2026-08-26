<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    // Listar todos los productos
    public function index()
    {
        $productos = Producto::with(['lotes' => function ($q) {
            $q->where('stock', '>', 0)->orderBy('fecha_vencimiento', 'asc');
        }])->get();

        return response()->json($productos, 200);
    }

    // Obtener un producto por ID
    public function show($id)
    {
        $producto = Producto::with(['lotes' => function ($q) {
            $q->where('stock', '>', 0)->orderBy('fecha_vencimiento', 'asc');
        }])->find($id);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($producto, 200);
    }

    // Crear un producto
    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo_barras'     => 'nullable|string|unique:productos,codigo_barras',
            'nombre'            => 'required|string|max:255',
            'principio_activo'  => 'nullable|string|max:255',
            'presentacion'      => 'nullable|string|max:255',
            'categoria'         => 'nullable|string|max:100',
            'precio_compra'     => 'nullable|numeric|min:0',
            'precio_venta'      => 'required|numeric|min:0',
            'stock_actual'      => 'nullable|integer|min:0',
            'stock_minimo'      => 'nullable|integer|min:0',
            'requiere_receta'   => 'nullable|boolean',
            'fecha_vencimiento' => 'nullable|date',
        ]);

        // Asignación de valores por defecto si no vienen en la petición
        $validated['precio_compra']   = $validated['precio_compra'] ?? 0;
        $validated['stock_actual']   = $validated['stock_actual'] ?? 0;
        $validated['stock_minimo']   = $validated['stock_minimo'] ?? 5;
        $validated['requiere_receta'] = $validated['requiere_receta'] ?? false;

        $producto = Producto::create($validated);

        return response()->json([
            'message' => 'Producto creado con éxito',
            'data'    => $producto
        ], 201);
    }

    // Actualizar un producto
    public function update(Request $request, $id)
    {
        $producto = Producto::find($id);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $validated = $request->validate([
            'codigo_barras'     => 'nullable|string|unique:productos,codigo_barras,' . $id,
            'nombre'            => 'sometimes|string|max:255',
            'presentacion'      => 'nullable|string|max:255',
            'precio_compra'     => 'nullable|numeric|min:0',
            'precio_venta'      => 'sometimes|numeric|min:0',
            'stock_actual'      => 'sometimes|integer|min:0',
            'requiere_receta'   => 'sometimes|boolean',
        ]);

        $producto->update($validated);

        return response()->json([
            'message' => 'Producto actualizado correctamente',
            'data'    => $producto
        ], 200);
    }

    // Búsqueda por lector de barras
    public function buscarPorCodigo($codigo)
    {
        $producto = Producto::with(['lotes' => function ($q) {
            $q->where('stock', '>', 0)->orderBy('fecha_vencimiento', 'asc');
        }])->where('codigo_barras', $codigo)->first();

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($producto, 200);
    }

    // Alertas
    public function alertas()
    {
        $stockBajo = Producto::whereRaw('stock_actual <= COALESCE(stock_minimo, 5)')->get();

        $proximosAVencer = Producto::whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '>=', now())
            ->whereDate('fecha_vencimiento', '<=', now()->addDays(30))
            ->get();

        $vencidos = Producto::whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '<', now())
            ->get();

        return response()->json([
            'stock_bajo'        => $stockBajo,
            'proximos_a_vencer' => $proximosAVencer,
            'vencidos'          => $vencidos,
        ], 200);
    }
}