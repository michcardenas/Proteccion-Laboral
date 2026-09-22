<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La pantalla de Gmail tiene que decir cuando una cuenta ya no sirve.
 *
 * Al mudar el servidor cambio el cliente OAuth y Google dejo de aceptar el
 * token guardado. La pantalla seguia en verde, «Conectado — se renovará
 * automáticamente», y el boton de conectar solo aparecia con la lista vacia:
 * no habia forma de arreglarlo desde la aplicacion.
 */
class ReconectarGmailTest extends TestCase
{
    use RefreshDatabase;

    private function director(): User
    {
        $role = Role::firstOrCreate(['name' => 'director', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'gmail.manage', 'guard_name' => 'web']));

        return tap(User::factory()->create())->assignRole($role);
    }

    private function cuenta(User $u, string $email): IntegrationToken
    {
        return IntegrationToken::create([
            'provider' => IntegrationToken::PROVIDER_GMAIL,
            'account_email' => $email,
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'expires_at' => now()->subDay(),
            'scopes' => [],
            'connected_by_user_id' => $u->id,
        ]);
    }

    /** @param  array<string, ?bool>  $estados  email => lo que responde Google */
    private function googleResponde(array $estados): void
    {
        $gmail = Mockery::mock(GmailService::class);
        $gmail->shouldReceive('sigueAutorizada')
            ->andReturnUsing(fn (IntegrationToken $t) => $estados[$t->account_email]);
        $this->app->instance(GmailService::class, $gmail);
    }

    public function test_marca_para_reconectar_la_cuenta_que_google_rechaza(): void
    {
        $director = $this->director();
        $this->cuenta($director, 'leidy@proteccionlaboral.co');
        $this->cuenta($director, 'automatizacion@proteccionlaboral.co');
        $this->googleResponde([
            'leidy@proteccionlaboral.co' => true,
            'automatizacion@proteccionlaboral.co' => false,
        ]);

        $this->actingAs($director)
            ->get(route('admin.integrations.gmail.status'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('connection.necesita_reconectar', true)
                ->where('cuentas.0.necesita_reconectar', false)
                ->where('cuentas.1.necesita_reconectar', true));
    }

    /** Si no se pudo preguntar a Google, no se alarma a nadie. */
    public function test_no_la_da_por_desconectada_si_google_no_responde(): void
    {
        $director = $this->director();
        $this->cuenta($director, 'automatizacion@proteccionlaboral.co');
        $this->googleResponde(['automatizacion@proteccionlaboral.co' => null]);

        $this->actingAs($director)
            ->get(route('admin.integrations.gmail.status'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('connection.necesita_reconectar', false));
    }
}
