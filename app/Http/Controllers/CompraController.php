<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\DetalleCompra;
use App\Models\InventoryMovement;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompraController extends Controller
{
    public function proveedores(Request $request)
    {
        return response()->json(Proveedor::query()
            ->when($request->filled('q'), fn ($query) => $query->where('nombre', 'like', '%' . $request->q . '%')->orWhere('ruc', 'like', '%' . $request->q . '%'))
            ->orderBy('nombre')->paginate(50));
    }

    public function guardarProveedor(Request $request, ?Proveedor $proveedor = null)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'ruc' => 'nullable|string|max:20|unique:proveedores,ruc' . ($proveedor ? ',' . $proveedor->id : ''),
            'contacto' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'direccion' => 'nullable|string|max:255',
            'activo' => 'boolean',
        ]);

        $proveedor = $proveedor ? tap($proveedor)->update($data) : Proveedor::create($data);
        ActivityLogger::log($request, $proveedor->wasRecentlyCreated ? 'supplier.created' : 'supplier.updated', Proveedor::class, $proveedor->id, []);
        return response()->json(['data' => $proveedor, 'message' => 'Proveedor guardado correctamente.'], $proveedor->wasRecentlyCreated ? 201 : 200);
    }

    public function index()
    {
        return response()->json(Compra::with(['proveedor:id,nombre', 'user:id,name', 'detalles.producto:id,nombre'])
            ->latest('fecha_recepcion')->paginate(25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'proveedor_id' => 'required|exists:proveedores,id',
            'numero_documento' => 'nullable|string|max:100',
            'detalles' => 'required|array|min:1',
            'detalles.*.producto_id' => 'required|exists:productos,id',
            'detalles.*.numero_lote' => 'required|string|max:100',
            'detalles.*.cantidad' => 'required|integer|min:1',
            'detalles.*.costo_unitario' => 'required|numeric|min:0',
            'detalles.*.fecha_vencimiento' => 'required|date|after:today',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $total = collect($data['detalles'])->sum(fn ($detail) => $detail['cantidad'] * $detail['costo_unitario']);
            $compra = Compra::create([
                'proveedor_id' => $data['proveedor_id'],
                'user_id' => $request->user()->id,
                'numero_documento' => $data['numero_documento'] ?? null,
                'total' => $total,
                'fecha_recepcion' => Carbon::now(),
            ]);

            foreach ($data['detalles'] as $detail) {
                $producto = Producto::findOrFail($detail['producto_id']);
                $subtotal = $detail['cantidad'] * $detail['costo_unitario'];
                $detalle = DetalleCompra::create([
                    'compra_id' => $compra->id,
                    'producto_id' => $detail['producto_id'],
                    'numero_lote' => $detail['numero_lote'],
                    'cantidad' => $detail['cantidad'],
                    'costo_unitario' => $detail['costo_unitario'],
                    'fecha_vencimiento' => $detail['fecha_vencimiento'],
                    'subtotal' => $subtotal,
                ]);
                $lote = Lote::create([
                    'producto_id' => $producto->id,
                    'numero_lote' => $detail['numero_lote'],
                    'stock' => $detail['cantidad'],
                    'fecha_vencimiento' => $detail['fecha_vencimiento'],
                ]);
                $producto->increment('stock_actual', $detail['cantidad']);
                $producto->update(['precio_compra' => $detail['costo_unitario']]);

                InventoryMovement::create([
                    'producto_id' => $producto->id,
                    'lote_id' => $lote->id,
                    'user_id' => $request->user()->id,
                    'tipo' => 'entrada_compra',
                    'cantidad' => $detail['cantidad'],
                    'referencia' => 'Compra #' . $compra->id,
                    'motivo' => $data['numero_documento'] ?? null,
                ]);
            }

            ActivityLogger::log($request, 'purchase.received', Compra::class, $compra->id, ['total' => (float) $compra->total, 'items' => count($data['detalles'])]);
            return response()->json(['data' => $compra->load(['proveedor', 'detalles.producto']), 'message' => 'Compra recibida y stock actualizado.'], 201);
        });
    }
}