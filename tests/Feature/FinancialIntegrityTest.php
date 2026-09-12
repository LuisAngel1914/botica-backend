<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_numbers_are_based_on_persisted_sale_ids(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $producto = Producto::create([
            'codigo_barras' => 'FIN-REC-001',
            'nombre' => 'Producto para comprobantes',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 2,
            'stock_minimo' => 1,
        ]);
        Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'FIN-REC-LOTE',
            'stock' => 2,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);

        $primeraVenta = $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/ventas', [
                'metodo_pago' => 'Efectivo',
                'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertCreated()
            ->json('data');

        $segundaVenta = $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/ventas', [
                'metodo_pago' => 'Efectivo',
                'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('B001-' . str_pad($primeraVenta['id'], 6, '0', STR_PAD_LEFT), $primeraVenta['numero_comprobante']);
        $this->assertSame('B001-' . str_pad($segundaVenta['id'], 6, '0', STR_PAD_LEFT), $segundaVenta['numero_comprobante']);
        $this->assertNotSame($primeraVenta['numero_comprobante'], $segundaVenta['numero_comprobante']);
    }

    public function test_daily_report_and_dashboard_cash_are_net_of_returns(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $producto = Producto::create([
            'codigo_barras' => 'FIN-NET-001',
            'nombre' => 'Producto para devolución',
            'precio_compra' => 5,
            'precio_venta' => 10,
            'stock_actual' => 3,
            'stock_minimo' => 1,
        ]);
        Lote::create([
            'producto_id' => $producto->id,
            'numero_lote' => 'FIN-NET-LOTE',
            'stock' => 3,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);
        Caja::create([
            'usuario_id' => $admin->id,
            'monto_inicial' => 50,
            'estado' => 'abierta',
            'fecha_apertura' => now()->subMinute(),
        ]);

        $ventaId = $this->actingAs($cajero, 'sanctum')
            ->postJson('/api/ventas', [
                'metodo_pago' => 'Efectivo',
                'detalles' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            ])
            ->assertCreated()
            ->json('venta_id');

        $detalleId = \App\Models\Venta::findOrFail($ventaId)->detalles()->firstOrFail()->id;

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/ventas/' . $ventaId . '/devoluciones', [
                'motivo' => 'El cliente devolvió una unidad en buen estado.',
                'detalles' => [['detalle_venta_id' => $detalleId, 'cantidad' => 1]],
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ventas/reporte-diario?fecha=' . now()->toDateString())
            ->assertOk()
            ->assertJsonPath('total_general', 10)
            ->assertJsonPath('devoluciones_total', 10)
            ->assertJsonPath('desglose_pagos.Efectivo', 10);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reportes/dashboard')
            ->assertOk()
            ->assertJsonPath('estado_caja.ventas_efectivo', 10)
            ->assertJsonPath('estado_caja.devoluciones_efectivo', 10)
            ->assertJsonPath('estado_caja.monto_esperado', 60);
    }
}
