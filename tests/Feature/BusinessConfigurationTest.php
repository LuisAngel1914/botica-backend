<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_identity_is_available_without_authentication(): void
    {
        $this->getJson('/api/configuracion/publica')
            ->assertOk()
            ->assertJsonPath('nombre_comercial', 'Botica L y L')
            ->assertJsonPath('serie_comprobante', 'B001')
            ->assertJsonMissingPath('created_at');
    }

    public function test_admin_can_update_settings_with_traceability(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $payload = array_merge(Configuracion::actual()->publicData(), [
            'nombre_comercial' => 'Farmacia Central',
            'razon_social' => 'Farmacia Central S.A.C.',
            'ruc' => '20123456789',
            'direccion' => 'Av. Principal 123',
            'telefono' => '999888777',
            'email' => 'contacto@farmacia.test',
            'serie_comprobante' => 'F001',
            'stock_minimo_default' => 8,
            'dias_alerta_vencimiento' => 45,
        ]);

        unset($payload['configuracion_completa'], $payload['campos_pendientes']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/configuracion', $payload)
            ->assertOk()
            ->assertJsonPath('configuracion.nombre_comercial', 'Farmacia Central')
            ->assertJsonPath('configuracion.configuracion_completa', true);

        $this->assertDatabaseHas('configuraciones', ['id' => 1, 'serie_comprobante' => 'F001']);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'business.settings_updated',
            'subject_type' => Configuracion::class,
            'subject_id' => 1,
        ]);
    }

    public function test_cashier_cannot_update_business_settings(): void
    {
        $cashier = User::factory()->create(['role' => 'cajero', 'activo' => true]);

        $this->actingAs($cashier, 'sanctum')
            ->putJson('/api/configuracion', [])
            ->assertForbidden();
    }

    public function test_internal_ticket_is_clearly_identified_and_marks_cancelled_sales(): void
    {
        $configuracion = Configuracion::actual();
        $configuracion->forceFill([
            'razon_social' => 'Auqui Vila Luz María',
            'ruc' => '10432182083',
            'direccion' => 'Av. A Mz. 147 Lt. 2A',
            'telefono' => '964420960',
            'email' => 'contacto@botica.test',
        ]);

        $venta = (object) [
            'id' => 7,
            'numero_comprobante' => 'B001-000007',
            'estado' => 'anulada',
            'created_at' => now(),
            'cliente' => null,
            'metodo_pago' => 'Efectivo',
            'total' => 4,
            'detalles' => collect([
                (object) [
                    'producto' => (object) ['nombre' => 'Amoxicilina 500gr'],
                    'cantidad' => 1,
                    'precio_unitario' => 4,
                    'subtotal' => 4,
                    'asignaciones' => collect(),
                ],
            ]),
        ];

        $html = view('tickets.venta', compact('venta', 'configuracion'))->render();

        $this->assertStringContainsString('TICKET INTERNO DE VENTA', $html);
        $this->assertStringNotContainsString('SIN VALIDEZ TRIBUTARIA', $html);
        $this->assertStringContainsString('VENTA ANULADA', $html);
        $this->assertStringContainsString('No reemplaza una boleta de venta autorizada', $html);
        $this->assertStringContainsString('Auqui Vila Luz María', $html);
    }

    public function test_any_authenticated_user_can_change_own_password(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cajero',
            'activo' => true,
            'password' => Hash::make('ClaveAnterior2026'),
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->patchJson('/api/perfil/password', [
                'password_actual' => 'ClaveAnterior2026',
                'password' => 'NuevaClave2027',
                'password_confirmation' => 'NuevaClave2027',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NuevaClave2027', $cashier->fresh()->password));
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $cashier->id,
            'action' => 'user.password_changed',
            'subject_id' => $cashier->id,
        ]);
    }

    public function test_current_password_is_required_to_change_credentials(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cajero',
            'activo' => true,
            'password' => Hash::make('ClaveAnterior2026'),
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->patchJson('/api/perfil/password', [
                'password_actual' => 'incorrecta',
                'password' => 'NuevaClave2027',
                'password_confirmation' => 'NuevaClave2027',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password_actual');

        $this->assertTrue(Hash::check('ClaveAnterior2026', $cashier->fresh()->password));
    }
}
