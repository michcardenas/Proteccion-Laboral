<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Activar portal a varios»: las abogadas abrian el portal cliente por
 * cliente. En lote solo entran los que de verdad podran iniciar sesion
 * (con NIT y con un proceso con abogado), para no repartir accesos que
 * terminan en «ninguno de tus procesos tiene abogado».
 */
class ActivarPortalEnLoteTest extends TestCase
{
    use RefreshDatabase;

    private User $abogada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Process::factory() toma un servicio existente; no hay factory de ServiceType.
        ServiceType::firstOrCreate(
            ['slug' => 'asesoria'],
            ['nombre' => 'Asesoría', 'descripcion' => 'test', 'modalidad' => 'permanente', 'es_activo' => true],
        );
        $this->abogada = tap(User::factory()->create())->assignRole('abogado_interno');
    }

    private function clienteConAbogado(array $attrs = []): Client
    {
        $client = Client::factory()->create($attrs + ['portal_activo' => false]);
        Process::factory()->create(['client_id' => $client->id, 'abogado_lider_id' => $this->abogada->id]);

        return $client;
    }

    public function test_activa_varios_y_devuelve_sus_contrasenas_una_vez(): void
    {
        $a = $this->clienteConAbogado();
        $b = $this->clienteConAbogado();

        $respuesta = $this->actingAs($this->abogada)
            ->post(route('admin.clients.portal.activate-bulk'), ['client_ids' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHas('portal_credentials_bulk');

        $creds = collect(session('portal_credentials_bulk'));
        $this->assertCount(2, $creds);
        $this->assertTrue($a->fresh()->portal_activo);
        $this->assertTrue($b->fresh()->portal_activo);

        // La provisional es el NIT sin puntos, sirve para entrar y obliga a
        // poner una propia antes de ver nada.
        $this->assertSame(Client::nitSinPuntos($a->nit), $creds->firstWhere('nit', $a->nit)['password']);
        $this->assertTrue($a->fresh()->debe_cambiar_clave);
        $this->post(route('portal.login.store'), [
            'nit' => $a->nit,
            'password' => $creds->firstWhere('nit', $a->nit)['password'],
        ])->assertRedirect(route('portal.password.edit'));
        $respuesta->assertSessionHasNoErrors();
    }

    public function test_salta_al_cliente_sin_proceso_con_abogado_y_al_que_ya_tiene_portal(): void
    {
        $bueno = $this->clienteConAbogado();
        $sinAbogado = Client::factory()->create(['portal_activo' => false]);
        Process::factory()->create(['client_id' => $sinAbogado->id]);
        $yaActivo = $this->clienteConAbogado(['portal_activo' => true, 'password' => 'la-de-antes']);

        $this->actingAs($this->abogada)
            ->post(route('admin.clients.portal.activate-bulk'), [
                'client_ids' => [$bueno->id, $sinAbogado->id, $yaActivo->id],
            ])
            ->assertSessionHas('portal_credentials_bulk', fn ($c) => count($c) === 1 && $c[0]['nit'] === $bueno->nit);

        $this->assertFalse($sinAbogado->fresh()->portal_activo);
        // No se le cambia la contraseña a quien ya tenia acceso.
        $this->assertTrue(password_verify('la-de-antes', $yaActivo->fresh()->password));
    }

    public function test_la_lista_de_candidatos_solo_trae_a_los_que_podran_entrar(): void
    {
        $bueno = $this->clienteConAbogado();
        Client::factory()->create(['portal_activo' => false]);
        $this->clienteConAbogado(['nit' => '']);

        $this->actingAs($this->abogada)
            ->get(route('admin.clients.index'))
            ->assertInertia(fn ($page) => $page
                ->has('portalCandidatos', 1)
                ->where('portalCandidatos.0.id', $bueno->id));
    }

    public function test_el_contador_no_activa_en_lote(): void
    {
        $client = $this->clienteConAbogado();
        $contador = tap(User::factory()->create())->assignRole('contador');

        $this->actingAs($contador)
            ->post(route('admin.clients.portal.activate-bulk'), ['client_ids' => [$client->id]])
            ->assertForbidden();

        $this->assertFalse($client->fresh()->portal_activo);
    }

    /** El mensaje de antes («proceso activo») hacia creer que faltaba algo de visibilidad. */
    public function test_el_login_dice_que_falta_el_abogado(): void
    {
        $client = Client::factory()->create(['portal_activo' => true, 'password' => 'secreto123']);
        Process::factory()->create(['client_id' => $client->id]);

        $this->post(route('portal.login.store'), ['nit' => $client->nit, 'password' => 'secreto123'])
            ->assertSessionHasErrors(['nit' => 'Tu acceso está activo, pero ninguno de tus procesos tiene todavía un abogado asignado. Contacta al despacho para que lo asigne.']);
    }
}
