<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Desconectar Gmail afecta a UNA cuenta.
 *
 * Borraba todas de golpe, que valía cuando el despacho compartía una sola
 * bandeja. Desde que cada abogada conecta la suya, un clic en «Desconectar»
 * dejaba a todo el mundo sin correo —y sin nada que avisara de que se estaban
 * llevando por delante las cuentas de los demás.
 */
class DesconectarUnaCuentaGmailTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        $role = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'gmail.manage', 'guard_name' => 'web']));

        return tap(User::factory()->create())->assignRole($role);
    }

    private function cuentaDe(User $u, string $email): IntegrationToken
    {
        return IntegrationToken::create([
            'provider' => IntegrationToken::PROVIDER_GMAIL,
            'account_email' => $email,
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
            'connected_by_user_id' => $u->id,
        ]);
    }

    public function test_desconectar_la_propia_no_toca_las_demas(): void
    {
        $leidy = $this->usuario('abogado_interno');
        $carolina = $this->usuario('abogado_interno');

        $suya = $this->cuentaDe($leidy, 'leidy@proteccionlaboral.co');
        $ajena = $this->cuentaDe($carolina, 'carolina@proteccionlaboral.co');

        $this->actingAs($leidy)
            ->post(route('admin.integrations.gmail.disconnect'), ['token_id' => $suya->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('integration_tokens', ['id' => $suya->id]);
        $this->assertDatabaseHas('integration_tokens', ['id' => $ajena->id]);
    }

    /** Y no se puede desconectar la de otra persona. */
    public function test_no_se_puede_desconectar_la_cuenta_ajena(): void
    {
        $leidy = $this->usuario('abogado_interno');
        $carolina = $this->usuario('abogado_interno');
        $ajena = $this->cuentaDe($carolina, 'carolina@proteccionlaboral.co');

        $this->actingAs($leidy)
            ->post(route('admin.integrations.gmail.disconnect'), ['token_id' => $ajena->id])
            ->assertForbidden();

        $this->assertDatabaseHas('integration_tokens', ['id' => $ajena->id]);
    }

    /** Dirección sí puede desconectar cualquiera. */
    public function test_el_director_puede_desconectar_cualquiera(): void
    {
        $director = $this->usuario('director');
        $leidy = $this->usuario('abogado_interno');
        $suya = $this->cuentaDe($leidy, 'leidy@proteccionlaboral.co');

        $this->actingAs($director)
            ->post(route('admin.integrations.gmail.disconnect'), ['token_id' => $suya->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('integration_tokens', ['id' => $suya->id]);
    }

    /** Sin decir cuál, no se desconecta nada. */
    public function test_sin_indicar_la_cuenta_no_borra_nada(): void
    {
        $director = $this->usuario('director');
        $this->cuentaDe($director, 'a@proteccionlaboral.co');
        $this->cuentaDe($director, 'b@proteccionlaboral.co');

        $this->actingAs($director)
            ->post(route('admin.integrations.gmail.disconnect'), [])
            ->assertSessionHasErrors('token_id');

        $this->assertSame(2, IntegrationToken::count());
    }
}
