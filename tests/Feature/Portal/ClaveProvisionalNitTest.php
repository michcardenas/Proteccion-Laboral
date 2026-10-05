<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Al activar el portal la clave provisional es el NIT sin puntos, y el
 * cliente pone una propia al entrar por primera vez: el NIT es publico.
 */
class ClaveProvisionalNitTest extends TestCase
{
    use RefreshDatabase;

    private User $abogada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        ServiceType::firstOrCreate(
            ['slug' => 'asesoria'],
            ['nombre' => 'Asesoría', 'descripcion' => 'test', 'modalidad' => 'permanente', 'es_activo' => true],
        );
        $this->abogada = tap(User::factory()->create())->assignRole('abogado_interno');
    }

    private function cliente(array $attrs = []): Client
    {
        $c = Client::factory()->create($attrs + ['nit' => '860.000.122', 'portal_activo' => false]);
        Process::factory()->create(['client_id' => $c->id, 'abogado_lider_id' => $this->abogada->id]);

        return $c;
    }

    public function test_nit_sin_puntos(): void
    {
        $this->assertSame('860000122', Client::nitSinPuntos('860.000.122-1'));
        $this->assertSame('900123456', Client::nitSinPuntos(' 900123456 '));
        $this->assertNull(Client::nitSinPuntos(null));
        $this->assertNull(Client::nitSinPuntos('—'));
    }

    public function test_activar_sin_escribir_clave_deja_el_nit_y_obliga_a_cambiarla(): void
    {
        $c = $this->cliente();

        $this->actingAs($this->abogada)
            ->post(route('admin.clients.portal.activate', $c))
            ->assertSessionHas('portal_credentials', ['nit' => '860.000.122', 'password' => '860000122']);

        $c->refresh();
        $this->assertTrue($c->portal_activo);
        $this->assertTrue($c->debe_cambiar_clave);
    }

    public function test_la_clave_escrita_por_la_abogada_tambien_es_provisional(): void
    {
        $c = $this->cliente();

        $this->actingAs($this->abogada)
            ->post(route('admin.clients.portal.activate', $c), ['password' => 'Temporal2026'])
            ->assertSessionHas('portal_credentials.password', 'Temporal2026');

        $this->assertTrue($c->fresh()->debe_cambiar_clave);
    }

    public function test_entra_con_el_nit_con_o_sin_puntos(): void
    {
        $c = $this->cliente(['portal_activo' => true, 'password' => '860000122', 'debe_cambiar_clave' => true]);

        foreach (['860.000.122', '860000122', '860000122-1'] as $usuario) {
            $this->post(route('portal.login.store'), ['nit' => $usuario, 'password' => '860000122'])
                ->assertRedirect(route('portal.password.edit'));
            $this->assertAuthenticatedAs($c, 'client');
            $this->post(route('portal.logout'));
        }
    }

    public function test_con_clave_provisional_no_ve_nada_hasta_cambiarla(): void
    {
        $c = $this->cliente(['portal_activo' => true, 'password' => '860000122', 'debe_cambiar_clave' => true]);

        $this->actingAs($c, 'client')->get(route('portal.dashboard'))->assertRedirect(route('portal.password.edit'));
        $this->actingAs($c, 'client')->get(route('portal.password.edit'))->assertOk();

        // El NIT (solo dígitos) no vale como clave propia.
        $this->actingAs($c, 'client')
            ->put(route('portal.password.update'), ['password' => '860000122', 'password_confirmation' => '860000122'])
            ->assertSessionHasErrors('password');
        $this->assertTrue($c->fresh()->debe_cambiar_clave);

        $this->actingAs($c, 'client')
            ->put(route('portal.password.update'), ['password' => 'Boluga2026', 'password_confirmation' => 'Boluga2026'])
            ->assertRedirect(route('portal.dashboard'));

        $c->refresh();
        $this->assertFalse($c->debe_cambiar_clave);
        $this->actingAs($c, 'client')->get(route('portal.dashboard'))->assertOk();

        // La nueva sirve para entrar y va directo al portal.
        $this->post(route('portal.logout'));
        $this->post(route('portal.login.store'), ['nit' => '860000122', 'password' => 'Boluga2026'])
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_quien_ya_tiene_su_clave_entra_sin_que_se_le_pida_cambiarla(): void
    {
        $this->cliente(['portal_activo' => true, 'password' => 'LaSuya2025', 'debe_cambiar_clave' => false]);

        $this->post(route('portal.login.store'), ['nit' => '860.000.122', 'password' => 'LaSuya2025'])
            ->assertRedirect(route('portal.dashboard'));
    }
}
