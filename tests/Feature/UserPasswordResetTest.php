<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_another_users_password_with_traceability(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $cajero = User::factory()->create([
            'role' => 'cajero',
            'activo' => true,
            'password' => Hash::make('password-anterior'),
        ]);
        $cajero->createToken('sesion-anterior');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/usuarios/'.$cajero->id.'/password', [
                'password' => 'NuevaClaveSegura2026',
                'password_confirmation' => 'NuevaClaveSegura2026',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Contraseña restablecida. Las sesiones anteriores del usuario fueron cerradas.');

        $this->assertTrue(Hash::check('NuevaClaveSegura2026', $cajero->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $cajero->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'user.password_reset',
            'subject_type' => User::class,
            'subject_id' => $cajero->id,
        ]);
    }

    public function test_password_reset_requires_confirmation_and_never_changes_password_on_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $passwordAnterior = $cajero->password;

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/usuarios/'.$cajero->id.'/password', [
                'password' => 'NuevaClaveSegura2026',
                'password_confirmation' => 'otra-clave',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame($passwordAnterior, $cajero->fresh()->password);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.password_reset']);
    }

    public function test_cajero_cannot_reset_passwords(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);
        $otroUsuario = User::factory()->create(['role' => 'cajero', 'activo' => true]);

        $this->actingAs($cajero, 'sanctum')
            ->patchJson('/api/usuarios/'.$otroUsuario->id.'/password', [
                'password' => 'NuevaClaveSegura2026',
                'password_confirmation' => 'NuevaClaveSegura2026',
            ])
            ->assertForbidden();
    }

    public function test_admin_cannot_reset_own_password_from_user_administration(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/usuarios/'.$admin->id.'/password', [
                'password' => 'NuevaClaveSegura2026',
                'password_confirmation' => 'NuevaClaveSegura2026',
            ])
            ->assertUnprocessable();
    }
}
