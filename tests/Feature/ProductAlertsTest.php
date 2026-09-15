<?php

namespace Tests\Feature;

use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_alerts_use_lot_expiry_dates_instead_of_legacy_product_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $porVencer = Producto::create([
            'codigo_barras' => 'ALERTA-001',
            'nombre' => 'Producto próximo a vencer',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 2,
            'stock_minimo' => 3,
            'fecha_vencimiento' => now()->addYear()->toDateString(),
        ]);
        Lote::create([
            'producto_id' => $porVencer->id,
            'numero_lote' => 'ALERTA-PROXIMO',
            'stock' => 2,
            'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        ]);

        $vencido = Producto::create([
            'codigo_barras' => 'ALERTA-002',
            'nombre' => 'Producto vencido',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 1,
            'stock_minimo' => 0,
        ]);
        Lote::create([
            'producto_id' => $vencido->id,
            'numero_lote' => 'ALERTA-VENCIDO',
            'stock' => 1,
            'fecha_vencimiento' => now()->subDay()->toDateString(),
        ]);

        $soloFechaAntigua = Producto::create([
            'codigo_barras' => 'ALERTA-003',
            'nombre' => 'Producto con fecha heredada',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 0,
            'stock_minimo' => 0,
            'fecha_vencimiento' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/productos/alertas')
            ->assertOk()
            ->assertJsonPath('stock_bajo.0.id', $porVencer->id)
            ->assertJsonPath('proximos_a_vencer.0.id', $porVencer->id)
            ->assertJsonPath('proximos_a_vencer.0.lotes.0.numero_lote', 'ALERTA-PROXIMO')
            ->assertJsonPath('vencidos.0.id', $vencido->id)
            ->assertJsonPath('vencidos.0.lotes.0.numero_lote', 'ALERTA-VENCIDO');

        $this->assertNotContains($soloFechaAntigua->id, collect($response->json('proximos_a_vencer'))->pluck('id')->all());
        $this->assertNotContains($soloFechaAntigua->id, collect($response->json('vencidos'))->pluck('id')->all());
    }
}
