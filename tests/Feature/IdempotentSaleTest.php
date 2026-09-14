<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotentSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_the_same_sale_request_creates_only_one_sale(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        Caja::create([
            'usuario_id' => $cajero->id,
            'monto_inicial' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now()->subMinute(),
        ]);
        $producto = Producto::create([
            'codigo_barras' => 'IDEMP-001',
            'nombre' => 'Producto idempotente',
            'precio_compra' => 4,
            'precio_venta' => 10,
            'stock_actual' => 2,
            'stock_minimo' => 1,
        ]);
        Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'IDEMP-LOTE-1',
            'stock' => 2,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);

        $payload = [
            'idempotency_key' => 'd2c7a1b8-2605-4a5f-8b93-d06d9de01e31',
            'metodo_pago' => 'Efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ];

        $firstSale = $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/ventas', $payload)
            ->assertCreated()
            ->assertJsonPath('idempotent', false);

        $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/ventas', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('venta_id', $firstSale->json('venta_id'));

        $this->assertDatabaseCount('ventas', 1);
        $this->assertSame(1, $producto->fresh()->stock_actual);
        $this->assertSame(1, Lote::firstOrFail()->stock);
    }
}
