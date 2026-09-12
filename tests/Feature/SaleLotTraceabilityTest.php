<?php

namespace Tests\Feature;

use App\Models\DetalleVentaLote;
use App\Models\InventoryMovement;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleLotTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_records_fefo_lot_assignments_and_cancellation_restores_the_same_lots(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $producto = Producto::create([
            'codigo_barras' => 'TRAZA-001',
            'nombre' => 'Producto trazable',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 5,
            'stock_minimo' => 1,
        ]);
        $loteProximo = Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'LOTE-FEFO-1',
            'stock' => 2,
            'fecha_vencimiento' => now()->addDays(20)->toDateString(),
        ]);
        $lotePosterior = Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'LOTE-FEFO-2',
            'stock' => 3,
            'fecha_vencimiento' => now()->addDays(50)->toDateString(),
        ]);

        $response = $this->actingAs($cajero, 'sanctum')->postJson('/api/ventas', [
            'metodo_pago' => 'Efectivo',
            'detalles' => [[
                'producto_id' => $producto->id,
                'cantidad' => 4,
            ]],
        ])->assertCreated();

        $venta = Venta::findOrFail($response->json('venta_id'));
        $detalle = $venta->detalles()->firstOrFail();

        $this->assertDatabaseHas('detalle_venta_lotes', [
            'detalle_venta_id' => $detalle->id,
            'lote_id' => $loteProximo->id,
            'cantidad' => 2,
        ]);
        $this->assertDatabaseHas('detalle_venta_lotes', [
            'detalle_venta_id' => $detalle->id,
            'lote_id' => $lotePosterior->id,
            'cantidad' => 2,
        ]);
        $this->assertSame(0, $loteProximo->fresh()->stock);
        $this->assertSame(1, $lotePosterior->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'lote_id' => $loteProximo->id,
            'tipo' => 'venta',
            'cantidad' => -2,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ventas')
            ->assertOk()
            ->assertJsonPath('0.detalles.0.asignaciones.0.lote.numero_lote', $loteProximo->numero_lote);

        $this->actingAs($admin, 'sanctum')
            ->get('/api/ventas/' . $venta->id . '/ticket')
            ->assertOk()
            ->assertSee($loteProximo->numero_lote);

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas/' . $venta->id . '/anular', [
            'motivo' => 'El cliente devolvió íntegramente los productos vendidos.',
        ])->assertOk();

        $this->assertSame(2, $loteProximo->fresh()->stock);
        $this->assertSame(3, $lotePosterior->fresh()->stock);
        $this->assertSame(5, $producto->fresh()->stock_actual);
        $this->assertSame(2, DetalleVentaLote::where('detalle_venta_id', $detalle->id)->count());
        $this->assertDatabaseHas('inventory_movements', [
            'lote_id' => $loteProximo->id,
            'tipo' => 'anulacion_venta',
            'cantidad' => 2,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'lote_id' => $lotePosterior->id,
            'tipo' => 'anulacion_venta',
            'cantidad' => 2,
        ]);
    }

    public function test_sale_rejects_stock_that_only_exists_in_expired_lots(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $producto = Producto::create([
            'codigo_barras' => 'VENC-001',
            'nombre' => 'Producto vencido',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 1,
            'stock_minimo' => 1,
        ]);
        Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'LOTE-VENCIDO',
            'stock' => 1,
            'fecha_vencimiento' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($cajero, 'sanctum')->postJson('/api/ventas', [
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertUnprocessable();

        $this->assertSame(1, $producto->fresh()->stock_actual);
    }
}
