<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Lote;
use App\Models\Producto;
use App\Services\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    public function index()
    {
        $productos = Producto::with(['lotes' => fn ($query) => $query->where('stock', '>', 0)->orderBy('fecha_vencimiento')])->get();
        return response()->json($productos);
    }

    public function registrarLote(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'numero_lote' => 'required|string|max:100',
            'stock' => 'required|integer|min:1',
            'fecha_vencimiento' => 'required|date|after:today',
            'motivo' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $lote = Lote::create($data);
            $producto = Producto::findOrFail($data['producto_id']);
            $producto->increment('stock_actual', $data['stock']);

            InventoryMovement::create([
                'producto_id' => $producto->id,
                'lote_id' => $lote->id,
                'user_id' => $request->user()->id,
                'tipo' => 'entrada_lote',
                'cantidad' => $data['stock'],
                'referencia' => $data['numero_lote'],
                'motivo' => $data['motivo'] ?? 'Ingreso de lote',
            ]);

            ActivityLogger::log($request, 'inventory.lot_received', Lote::class, $lote->id, [
                'producto' => $producto->nombre,
                'lote' => $lote->numero_lote,
                'cantidad' => (int) $lote->stock,
                'fecha_vencimiento' => $lote->fecha_vencimiento,
            ]);

            return response()->json(['message' => 'Lote ingresado con éxito', 'lote' => $lote], 201);
        });
    }

    public function porVencer()
    {
        $limite = Carbon::now()->addDays(60);
        $lotes = Lote::with('producto')
            ->where('stock', '>', 0)
            ->where('fecha_vencimiento', '<=', $limite)
            ->orderBy('fecha_vencimiento')
            ->get();

        return response()->json($lotes);
    }

    public function movimientos(Request $request)
    {
        $movimientos = InventoryMovement::with(['producto:id,nombre,imagen_url', 'lote:id,numero_lote', 'user:id,name'])
            ->when($request->filled('producto_id'), fn ($query) => $query->where('producto_id', $request->producto_id))
            ->latest()
            ->paginate(25);

        return response()->json($movimientos);
    }
}