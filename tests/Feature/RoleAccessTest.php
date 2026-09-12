<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cajero_cannot_access_global_sales_or_administration(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $lote = $this->crearLoteConStock();

        $this->actingAs($cajero, 'sanctum')->getJson('/api/ventas')->assertForbidden();
        $this->actingAs($cajero, 'sanctum')->getJson('/api/ventas/reporte-diario')->assertForbidden();
        $this->actingAs($cajero, 'sanctum')->getJson('/api/usuarios')->assertForbidden();
        $this->actingAs($cajero, 'sanctum')->postJson('/api/inventario/lotes/' . $lote->id . '/baja', [
            'tipo' => 'vencimiento',
            'cantidad' => 1,
            'motivo' => 'Prueba de autorización de inventario.',
        ])->assertForbidden();
    }

    public function test_admin_can_access_global_sales_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ventas')
            ->assertOk();
    }

    public function test_admin_can_register_a_traced_lot_disposal(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $lote = $this->crearLoteConStock();

        $this->actingAs($admin, 'sanctum')->postJson('/api/inventario/lotes/' . $lote->id . '/baja', [
            'tipo' => 'vencimiento',
            'cantidad' => 2,
            'motivo' => 'Lote vencido retirado del área de venta.',
        ])->assertOk();

        $this->assertSame(3, $lote->fresh()->stock);
        $this->assertSame(3, $lote->producto->fresh()->stock_actual);
        $this->assertDatabaseHas('inventory_movements', [
            'lote_id' => $lote->id,
            'user_id' => $admin->id,
            'tipo' => 'baja_vencimiento',
            'cantidad' => -2,
        ]);
    }

    private function crearLoteConStock(): Lote
    {
        $producto = Producto::create([
            'codigo_barras' => 'TEST-' . fake()->unique()->numerify('#####'),
            'nombre' => 'Producto de prueba',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 5,
            'stock_minimo' => 1,
        ]);

        return Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'LOTE-TEST-' . fake()->unique()->numerify('#####'),
            'stock' => 5,
            'fecha_vencimiento' => now()->subDay()->toDateString(),
        ]);
    }
}
