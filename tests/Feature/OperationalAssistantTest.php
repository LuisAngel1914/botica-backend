<?php

namespace Tests\Feature;

use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_returns_only_products_with_current_sellable_stock(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);

        $vigente = Producto::create([
            'codigo_barras' => 'ASIS-001',
            'nombre' => 'Paracetamol Forte',
            'principio_activo' => 'Paracetamol',
            'presentacion' => 'Caja x 20 tabletas',
            'precio_compra' => 5,
            'precio_venta' => 12.5,
            'stock_actual' => 4,
            'stock_minimo' => 1,
            'condicion_venta' => 'libre',
        ]);
        Lote::create([
            'producto_id' => $vigente->id,
            'numero_lote' => 'ASIS-VIGENTE',
            'stock' => 4,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);

        $vencido = Producto::create([
            'codigo_barras' => 'ASIS-002',
            'nombre' => 'Paracetamol vencido',
            'principio_activo' => 'Paracetamol',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 5,
            'stock_minimo' => 1,
        ]);
        Lote::create([
            'producto_id' => $vencido->id,
            'numero_lote' => 'ASIS-VENCIDO',
            'stock' => 5,
            'fecha_vencimiento' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/chat', ['mensaje' => '¿Tienen paracetamol?'])
            ->assertOk()
            ->assertJsonPath('code', 'CATALOG_RESULTS')
            ->assertJsonPath('productos.0.id', $vigente->id)
            ->assertJsonPath('productos.0.stock_disponible', 4)
            ->assertJsonPath('productos.0.principio_activo', 'Paracetamol')
            ->assertJsonCount(1, 'productos');
    }

    public function test_assistant_refuses_medical_advice_without_calling_the_catalog(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);

        $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/chat', ['mensaje' => '¿Qué dosis debo tomar para el dolor?'])
            ->assertOk()
            ->assertJsonPath('code', 'MEDICAL_ADVICE_UNAVAILABLE')
            ->assertJsonPath('productos', []);
    }

    public function test_assistant_rejects_questions_outside_of_the_pharmacy_catalog(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);

        $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/chat', ['mensaje' => '¿Cuál es la capital de Francia?'])
            ->assertOk()
            ->assertJsonPath('code', 'OUT_OF_SCOPE')
            ->assertJsonPath('productos', []);
    }
}
