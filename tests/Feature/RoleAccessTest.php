<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cajero_cannot_access_global_sales_or_administration(): void
    {
        $cajero = User::factory()->create(['role' => 'cajero', 'activo' => true]);

        $this->actingAs($cajero, 'sanctum')->getJson('/api/ventas')->assertForbidden();
        $this->actingAs($cajero, 'sanctum')->getJson('/api/ventas/reporte-diario')->assertForbidden();
        $this->actingAs($cajero, 'sanctum')->getJson('/api/usuarios')->assertForbidden();
    }

    public function test_admin_can_access_global_sales_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'activo' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ventas')
            ->assertOk();
    }
}
