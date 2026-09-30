<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\ComprobanteElectronico;
use App\Models\Configuracion;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElectronicReceiptDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_creates_a_traceable_demo_receipt_without_claiming_sunat_acceptance(): void
    {
        [$cashier, $product] = $this->salesFixture();

        $response = $this->actingAs($cashier, 'sanctum')->postJson('/api/ventas', [
            'idempotency_key' => '77777777-7777-4777-8777-777777777777',
            'metodo_pago' => 'Efectivo',
            'detalles' => [['producto_id' => $product->id, 'cantidad' => 1]],
        ])->assertCreated();

        $response
            ->assertJsonPath('data.numero_comprobante', 'B001-00000001')
            ->assertJsonPath('data.comprobante.modo', 'demo')
            ->assertJsonPath('data.comprobante.estado', 'simulado')
            ->assertJsonPath('data.comprobante.eventos.0.evento', 'demo.generated');

        $receipt = ComprobanteElectronico::firstOrFail();
        $this->assertSame('03', $receipt->payload['tipo_documento']);
        $this->assertSame('demo', $receipt->payload['entorno']);
        $this->assertNull($receipt->enviado_at);
        $this->assertNull($receipt->aceptado_at);
        $this->assertSame(64, strlen($receipt->hash));

        $this->actingAs($cashier, 'sanctum')
            ->get('/api/ventas/' . $response->json('venta_id') . '/ticket')
            ->assertOk()
            ->assertSee('MODO PRUEBAS')
            ->assertSee('SIN VALIDEZ TRIBUTARIA')
            ->assertSee('NO ENVIADO A SUNAT');
    }

    public function test_cancelling_a_demo_sale_keeps_receipt_history(): void
    {
        [$cashier, $product] = $this->salesFixture();
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $saleId = $this->actingAs($cashier, 'sanctum')->postJson('/api/ventas', [
            'idempotency_key' => '88888888-8888-4888-8888-888888888888',
            'detalles' => [['producto_id' => $product->id, 'cantidad' => 1]],
        ])->assertCreated()->json('venta_id');

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas/' . $saleId . '/anular', [
            'motivo' => 'Error de registro confirmado por administración.',
        ])->assertOk();

        $receipt = ComprobanteElectronico::with('eventos')->firstOrFail();
        $this->assertSame('anulado_demo', $receipt->estado);
        $this->assertSame(['demo.generated', 'demo.cancelled'], $receipt->eventos->pluck('evento')->all());
        $this->assertSame('Error de registro confirmado por administración.', $receipt->eventos->last()->detalles['motivo']);
    }

    public function test_production_mode_fails_closed_until_an_official_provider_is_configured(): void
    {
        [$cashier, $product] = $this->salesFixture();
        Configuracion::actual()->update(['modo_emision_comprobantes' => 'produccion']);

        $this->actingAs($cashier, 'sanctum')->postJson('/api/ventas', [
            'idempotency_key' => '99999999-9999-4999-8999-999999999999',
            'detalles' => [['producto_id' => $product->id, 'cantidad' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('comprobante');

        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('comprobantes_electronicos', 0);
        $this->assertSame(3, $product->fresh()->stock_actual);
        $this->assertSame(3, Lote::firstOrFail()->stock);
    }

    private function salesFixture(): array
    {
        $cashier = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        Caja::create([
            'usuario_id' => $cashier->id,
            'monto_inicial' => 0,
            'estado' => 'abierta',
            'fecha_apertura' => now()->subMinute(),
        ]);
        $product = Producto::create([
            'codigo_barras' => 'CPE-DEMO-001',
            'nombre' => 'Producto demostrativo',
            'precio_compra' => 3,
            'precio_venta' => 8,
            'stock_actual' => 3,
            'stock_minimo' => 1,
        ]);
        Lote::create([
            'producto_id' => $product->id,
            'numero_lote' => 'CPE-DEMO-LOTE',
            'stock' => 3,
            'fecha_vencimiento' => now()->addMonth()->toDateString(),
        ]);

        return [$cashier, $product];
    }
}
