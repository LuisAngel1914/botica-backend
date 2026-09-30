<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Caja;
use App\Models\CashClosureCorrection;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CriticalBusinessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_operational_shift_preserves_sales_inventory_cash_and_audit_trace(): void
    {
        Http::fake([
            'https://api.apis.net.pe/v1/dni*' => Http::response([
                'nombres' => 'MERLIN ALINA',
                'apellidoPaterno' => 'SINCHE',
                'apellidoMaterno' => 'CHARCA',
            ]),
        ]);

        $admin = User::factory()->create([
            'name' => 'Administrador QA',
            'email' => 'admin.acceptance@botica.test',
            'password' => Hash::make('ClaveAdmin2026'),
            'role' => 'admin',
            'activo' => true,
        ]);
        $cashier = User::factory()->create([
            'name' => 'Cajero QA',
            'email' => 'cajero.acceptance@botica.test',
            'password' => Hash::make('ClaveCajero2026'),
            'role' => 'cajero',
            'activo' => true,
        ]);

        $product = Producto::create([
            'codigo_barras' => 'E2E-001',
            'nombre' => 'Producto de aceptación',
            'precio_compra' => 6,
            'precio_venta' => 10,
            'stock_actual' => 5,
            'stock_minimo' => 1,
            'condicion_venta' => 'libre',
        ]);
        $firstLot = Lote::create([
            'producto_id' => $product->id,
            'numero_lote' => 'E2E-LOTE-01',
            'stock' => 2,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);
        $secondLot = Lote::create([
            'producto_id' => $product->id,
            'numero_lote' => 'E2E-LOTE-02',
            'stock' => 3,
            'fecha_vencimiento' => now()->addDays(60)->toDateString(),
        ]);

        $cashierToken = $this->postJson('/api/login', [
            'email' => $cashier->email,
            'password' => 'ClaveCajero2026',
        ])->assertOk()->assertJsonPath('user.role', 'cajero')->json('access_token');

        $this->withToken($cashierToken);
        $customer = $this->getJson('/api/clientes/buscar/42092755')
            ->assertOk()
            ->assertJsonPath('origen', 'reniec')
            ->assertJsonPath('data.nombre_razon_social', 'MERLIN ALINA SINCHE CHARCA')
            ->json('data');

        $cashRegisterId = $this->postJson('/api/caja/abrir', ['monto_inicial' => 100])
            ->assertCreated()
            ->json('caja.id');

        $cashSaleId = $this->postJson('/api/ventas', [
            'idempotency_key' => '10000000-0000-4000-8000-000000000001',
            'cliente_datos' => $customer,
            'metodo_pago' => 'Efectivo',
            'detalles' => [['producto_id' => $product->id, 'cantidad' => 3]],
        ])->assertCreated()->json('venta_id');

        $digitalSaleId = $this->postJson('/api/ventas', [
            'idempotency_key' => '10000000-0000-4000-8000-000000000002',
            'metodo_pago' => 'Yape',
            'detalles' => [['producto_id' => $product->id, 'cantidad' => 1]],
        ])->assertCreated()->json('venta_id');

        $this->assertSame(1, $product->fresh()->stock_actual);
        $this->assertSame(0, $firstLot->fresh()->stock);
        $this->assertSame(1, $secondLot->fresh()->stock);

        $this->get('/api/ventas/' . $cashSaleId . '/ticket')
            ->assertOk()
            ->assertSee('TICKET INTERNO DE VENTA')
            ->assertSee('42092755')
            ->assertSee('MERLIN ALINA SINCHE CHARCA')
            ->assertDontSee('SIN VALIDEZ TRIBUTARIA')
            ->assertSee('E2E-LOTE-01');

        $adminToken = $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'ClaveAdmin2026',
        ])->assertOk()->assertJsonPath('user.role', 'admin')->json('access_token');

        $this->assertNotEmpty($adminToken);
        Sanctum::actingAs($admin);
        $cashSaleDetailId = Venta::findOrFail($cashSaleId)->detalles()->firstOrFail()->id;
        $this->postJson('/api/ventas/' . $cashSaleId . '/devoluciones', [
            'motivo' => 'Devolución parcial validada en prueba integral.',
            'detalles' => [['detalle_venta_id' => $cashSaleDetailId, 'cantidad' => 1]],
        ])->assertCreated()->assertJsonPath('devolucion.total', 10);

        $this->postJson('/api/ventas/' . $digitalSaleId . '/anular', [
            'motivo' => 'Anulación completa validada en prueba integral.',
        ])->assertOk();

        $this->assertSame(3, $product->fresh()->stock_actual);
        $this->assertSame(1, $firstLot->fresh()->stock);
        $this->assertSame(2, $secondLot->fresh()->stock);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/caja/cerrar', ['monto_final' => 118])
            ->assertOk()
            ->assertJsonPath('resumen.monto_esperado', 120)
            ->assertJsonPath('resumen.diferencia', -2);

        Sanctum::actingAs($admin);
        $this->postJson('/api/caja/' . $cashRegisterId . '/correcciones', [
            'monto_final_corregido' => 120,
            'motivo' => 'Se corrigió el conteo después de verificar el efectivo.',
        ])->assertCreated();

        $this->getJson('/api/ventas/reporte-diario?fecha=' . now()->toDateString())
            ->assertOk()
            ->assertJsonPath('total_general', 20)
            ->assertJsonPath('ventas_anuladas', 1)
            ->assertJsonPath('devoluciones_total', 10)
            ->assertJsonPath('desglose_pagos.Efectivo', 20);

        $closedCashRegister = Caja::findOrFail($cashRegisterId);
        $this->assertSame(118.0, (float) $closedCashRegister->monto_final);
        $this->assertDatabaseHas('cash_closure_corrections', [
            'caja_id' => $cashRegisterId,
            'user_id' => $admin->id,
            'monto_final_corregido' => 120,
        ]);
        $this->assertSame(120.0, (float) CashClosureCorrection::where('caja_id', $cashRegisterId)->value('monto_final_corregido'));

        foreach ([
            'cash_register.opened',
            'sale.created',
            'sale.partially_returned',
            'sale.cancelled',
            'cash_register.closed',
            'cash_register.closure_corrected',
        ] as $action) {
            $this->assertTrue(ActivityLog::where('action', $action)->exists(), "Falta trazabilidad para {$action}");
        }
    }
}
