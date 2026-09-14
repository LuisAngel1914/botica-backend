<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiSessionReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_returns_a_clear_message_for_an_expired_or_missing_session(): void
    {
        $this->getJson('/api/productos')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_EXPIRED')
            ->assertJsonPath('message', 'Tu sesión venció o ya no es válida. Inicia sesión nuevamente para continuar.');
    }

    public function test_invalid_login_keeps_its_own_safe_credential_message(): void
    {
        $user = User::factory()->create([
            'email' => 'operador@botica.test',
            'password' => Hash::make('correct-password'),
            'activo' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Las credenciales proporcionadas son incorrectas.')
            ->assertJsonMissingPath('code');
    }
}
