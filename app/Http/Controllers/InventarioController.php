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

    public function registrarBaja(Request $request, Lote $lote)
    {
        $data = $request->validate([
            'tipo' => 'required|in:vencimiento,merma',
            'cantidad' => 'required|integer|min:1',
            'motivo' => 'required|string|min:10|max:1000',
        ]);

        return DB::transaction(function () use ($data, $request, $lote) {
            $lote = Lote::lockForUpdate()->findOrFail($lote->id);
            if ($data['cantidad'] > $lote->stock) {
                return response()->json(['message' => 'La cantidad supera el stock disponible del lote.'], 422);
            }

            $producto = Producto::lockForUpdate()->findOrFail($lote->producto_id);
            if ($data['cantidad'] > $producto->stock_actual) {
                return response()->json(['message' => 'El stock consolidado no permite registrar esta baja.'], 422);
            }

            $lote->decrement('stock', $data['cantidad']);
            $producto->decrement('stock_actual', $data['cantidad']);

            $tipoMovimiento = 'baja_' . $data['tipo'];
            InventoryMovement::create([
                'producto_id' => $producto->id,
                'lote_id' => $lote->id,
                'user_id' => $request->user()->id,
                'tipo' => $tipoMovimiento,
                'cantidad' => -$data['cantidad'],
                'referencia' => 'Lote ' . $lote->numero_lote,
                'motivo' => $data['motivo'],
            ]);

            ActivityLogger::log($request, 'inventory.disposed', Lote::class, $lote->id, [
                'producto' => $producto->nombre,
                'lote' => $lote->numero_lote,
                'tipo' => $data['tipo'],
                'cantidad' => (int) $data['cantidad'],
                'motivo' => $data['motivo'],
            ]);

            return response()->json([
                'message' => 'Baja de inventario registrada correctamente.',
                'lote' => $lote->fresh(),
                'producto' => $producto->fresh(),
            ]);
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
        $data = $request->validate([
            'producto_id' => 'nullable|integer|exists:productos,id',
            'lote_id' => 'nullable|integer|exists:lotes,id',
            'tipo' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer|exists:users,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            $this->consultaMovimientos($data)->paginate($data['per_page'] ?? 25)->withQueryString()
        );
    }

    public function exportarMovimientos(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'nullable|integer|exists:productos,id',
            'lote_id' => 'nullable|integer|exists:lotes,id',
            'tipo' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer|exists:users,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $movimientos = $this->consultaMovimientos($data)->cursor();
        $fileName = 'historial-inventario-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($movimientos) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Fecha', 'Producto', 'Lote', 'Tipo', 'Cantidad', 'Responsable', 'Referencia', 'Motivo']);

            foreach ($movimientos as $movimiento) {
                fputcsv($output, [
                    optional($movimiento->created_at)->format('Y-m-d H:i:s'),
                    $movimiento->producto?->nombre,
                    $movimiento->lote?->numero_lote,
                    $movimiento->tipo,
                    $movimiento->cantidad,
                    $movimiento->user?->name,
                    $movimiento->referencia,
                    $movimiento->motivo,
                ]);
            }

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function consultaMovimientos(array $filters)
    {
        return InventoryMovement::with(['producto:id,nombre,imagen_url', 'lote:id,numero_lote', 'user:id,name'])
            ->when(isset($filters['producto_id']), fn ($query) => $query->where('producto_id', $filters['producto_id']))
            ->when(isset($filters['lote_id']), fn ($query) => $query->where('lote_id', $filters['lote_id']))
            ->when(isset($filters['tipo']), fn ($query) => $query->where('tipo', $filters['tipo']))
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['fecha_inicio']), fn ($query) => $query->whereDate('created_at', '>=', $filters['fecha_inicio']))
            ->when(isset($filters['fecha_fin']), fn ($query) => $query->whereDate('created_at', '<=', $filters['fecha_fin']))
            ->latest();
    }
}