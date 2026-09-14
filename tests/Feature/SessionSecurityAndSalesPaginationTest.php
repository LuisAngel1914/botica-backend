<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionSecurityAndSalesPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_login_invalidates_the_previous_access_token(): void
    {
        $user = User::factory()->create([
            'email' => 'operador@example.test',
            'password' => Hash::make('clave-segura'),
            'role' => 'cajero',
            'activo' => true,
        ]);

        $firstToken = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'clave-segura',
        ])->assertOk()->json('access_token');

        $secondToken = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'clave-segura',
        ])->assertOk()->json('access_token');

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer ' . $firstToken)
            ->getJson('/api/caja/estado')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $secondToken)
            ->getJson('/api/caja/estado')
            ->assertOk();
    }

    public function test_sales_history_returns_paginated_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ventas?per_page=15&page=1')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
            ])
            ->assertJsonPath('per_page', 15);
    }
}
