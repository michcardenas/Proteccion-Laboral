<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quién puede abrir y cerrar el portal de un cliente.
 *
 * `clients.activate_portal` se creó justo para que las abogadas activaran el
 * portal sin darles la edición completa del cliente. La ruta ya lo exigía,
 * pero el controlador volvía a pedir `clients.update`: el botón aparecía y el
 * clic terminaba en 403 para abogado interno, externo y apoderado.
 */
class ActivarPortalTest extends TestCase
{
    use RefreshDatabase;

    private function con(string $rol): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return tap(User::factory()->create())->assignRole($rol);
    }

    public static function rolesQueActivan(): array
    {
        return [['abogado_interno'], ['abogado_externo'], ['apoderado']];
    }

    #[DataProvider('rolesQueActivan')]
    public function test_las_abogadas_activan_el_portal_sin_editar_el_cliente(string $rol): void
    {
        $client = Client::factory()->create(['portal_activo' => false]);

        $this->actingAs($this->con($rol))
            ->post(route('admin.clients.portal.activate', $client))
            ->assertRedirect()
            ->assertSessionHas('portal_credentials');

        $this->assertTrue($client->fresh()->portal_activo);
    }

    #[DataProvider('rolesQueActivan')]
    public function test_las_abogadas_desactivan_el_portal(string $rol): void
    {
        $client = Client::factory()->create(['portal_activo' => true]);

        $this->actingAs($this->con($rol))
            ->post(route('admin.clients.portal.deactivate', $client))
            ->assertRedirect();

        $this->assertFalse($client->fresh()->portal_activo);
    }

    /** El contador ve clientes pero no maneja su acceso. */
    public function test_el_contador_no_activa_el_portal(): void
    {
        $client = Client::factory()->create(['portal_activo' => false]);

        $this->actingAs($this->con('contador'))
            ->post(route('admin.clients.portal.activate', $client))
            ->assertForbidden();

        $this->assertFalse($client->fresh()->portal_activo);
    }
}
