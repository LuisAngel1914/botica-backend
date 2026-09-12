<?php

namespace Tests\Feature;

use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartialSaleReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_partially_return_a_sale_to_its_original_lots(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $producto = Producto::create(['codigo_barras' => 'DEV-001', 'nombre' => 'Producto retornable', 'precio_compra' => 5, 'precio_venta' => 10, 'stock_actual' => 4, 'stock_minimo' => 1]);
        $primerLote = Lote::create(['producto_id' => $producto->id, 'numero_lote' => 'DEV-LOTE-1', 'stock' => 2, 'fecha_vencimiento' => now()->addDays(10)->toDateString()]);
        $segundoLote = Lote::create(['producto_id' => $producto->id, 'numero_lote' => 'DEV-LOTE-2', 'stock' => 2, 'fecha_vencimiento' => now()->addDays(20)->toDateString()]);

        $ventaId = $this->actingAs($cajero, 'sanctum')->postJson('/api/ventas', ['metodo_pago' => 'Efectivo', 'detalles' => [['producto_id' => $producto->id, 'cantidad' => 3]]])->assertCreated()->json('venta_id');
        $detalle = Venta::findOrFail($ventaId)->detalles()->firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas/' . $ventaId . '/devoluciones', [
            'motivo' => 'El cliente devolvió dos unidades en buen estado.',
            'detalles' => [['detalle_venta_id' => $detalle->id, 'cantidad' => 2]],
        ])->assertCreated()->assertJsonPath('devolucion.total', 20);

        $this->assertSame(2, $primerLote->fresh()->stock);
        $this->assertSame(1, $segundoLote->fresh()->stock);
        $this->assertSame(3, $producto->fresh()->stock_actual);
        $this->assertDatabaseHas('inventory_movements', ['lote_id' => $primerLote->id, 'tipo' => 'devolucion_venta', 'cantidad' => 2]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas/' . $ventaId . '/devoluciones', [
            'motivo' => 'Intento de devolución superior a lo vendido.',
            'detalles' => [['detalle_venta_id' => $detalle->id, 'cantidad' => 2]],
        ])->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas/' . $ventaId . '/anular', [
            'motivo' => 'No debe anularse una venta con devolución parcial.',
        ])->assertUnprocessable();
    }
}
