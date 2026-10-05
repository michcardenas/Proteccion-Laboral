<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leidy: «deben quedar todos creados como activos para no asignar uno a
 * uno». El cliente con NIT nace con el portal activo y el NIT como clave
 * provisional (que cambia al entrar).
 */
class PortalActivoAlCrearTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinadora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->coordinadora = tap(User::factory()->create(['is_active' => true]))->assignRole('coordinador');
    }

    public function test_crear_un_cliente_con_nit_le_activa_el_portal(): void
    {
        $this->actingAs($this->coordinadora)
            ->post(route('admin.clients.store'), ['razon_social' => 'Empresa Nueva SAS', 'nit' => '901.222.333', 'estado' => 'activo'])
            ->assertRedirect()
            ->assertSessionHas('portal_credentials', ['nit' => '901.222.333', 'password' => '901222333']);

        $c = Client::where('razon_social', 'Empresa Nueva SAS')->sole();
        $this->assertTrue($c->portal_activo);
        $this->assertTrue($c->debe_cambiar_clave);
    }

    public function test_sin_nit_se_crea_sin_portal(): void
    {
        $this->actingAs($this->coordinadora)
            ->post(route('admin.clients.store'), ['razon_social' => 'Sin NIT SAS', 'estado' => 'activo'])
            ->assertSessionMissing('portal_credentials.password');

        $this->assertFalse(Client::where('razon_social', 'Sin NIT SAS')->sole()->portal_activo);
    }

    public function test_al_ponerle_el_nit_a_uno_de_drive_se_activa(): void
    {
        $c = Client::factory()->create(['nit' => null, 'portal_activo' => false, 'password' => null]);

        $this->actingAs($this->coordinadora)
            ->put(route('admin.clients.update', $c), ['razon_social' => $c->razon_social, 'nit' => '900111222', 'estado' => 'activo'])
            ->assertSessionHas('portal_credentials.password', '900111222');

        $this->assertTrue($c->fresh()->portal_activo);
    }

    public function test_editar_a_uno_que_ya_tenia_portal_o_clave_no_lo_toca(): void
    {
        $desactivado = Client::factory()->create(['nit' => null, 'portal_activo' => false, 'password' => 'SuClave2025']);
        $this->actingAs($this->coordinadora)
            ->put(route('admin.clients.update', $desactivado), ['razon_social' => $desactivado->razon_social, 'nit' => '900333444', 'estado' => 'activo']);
        // Tenía clave: alguien lo desactivó a propósito.
        $this->assertFalse($desactivado->fresh()->portal_activo);

        $activo = Client::factory()->create(['nit' => '900555666', 'portal_activo' => true, 'password' => 'SuClave2025', 'debe_cambiar_clave' => false]);
        $this->actingAs($this->coordinadora)
            ->put(route('admin.clients.update', $activo), ['razon_social' => 'Otro nombre', 'nit' => '900555666', 'estado' => 'activo']);
        $this->assertFalse($activo->fresh()->debe_cambiar_clave);
    }
}
