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
        $this->actingAs($cajero, 'sanctum')->getJson('/api/inventario/movimientos')->assertForbidden();
        $this->actingAs($cajero, 'sanctum')->getJson('/api/inventario/movimientos/exportar')->assertForbidden();
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

    public function test_admin_can_filter_and_export_inventory_movement_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $lote = $this->crearLoteConStock();

        InventoryMovement::create([
            'producto_id' => $lote->producto_id,
            'lote_id' => $lote->id,
            'user_id' => $admin->id,
            'tipo' => 'entrada_lote',
            'cantidad' => 5,
            'referencia' => $lote->numero_lote,
            'motivo' => 'Ingreso inicial para prueba de auditoría.',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/inventario/movimientos?producto_id=' . $lote->producto_id . '&lote_id=' . $lote->id . '&tipo=entrada_lote&user_id=' . $admin->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.lote.numero_lote', $lote->numero_lote)
            ->assertJsonPath('data.0.user.id', $admin->id);

        $this->actingAs($admin, 'sanctum')
            ->get('/api/inventario/movimientos/exportar?producto_id=' . $lote->producto_id)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
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
