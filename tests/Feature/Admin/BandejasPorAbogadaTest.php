<?php

namespace Tests\Feature\Admin;

use App\Models\EmailIngestion;
use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cada abogada ve su bandeja; el director las ve todas.
 *
 * Mientras hubo una sola cuenta de Gmail compartida, todo el mundo veía el
 * correo de todos los clientes. Con una cuenta por abogada eso deja de ser
 * aceptable, y el reparto lo decide de qué cuenta entró el correo.
 */
class BandejasPorAbogadaTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        $role = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'emails.review', 'guard_name' => 'web']));

        return tap(User::factory()->create())->assignRole($role);
    }

    private function cuentaDe(?User $u, string $email): IntegrationToken
    {
        return IntegrationToken::create([
            'provider' => IntegrationToken::PROVIDER_GMAIL,
            'account_email' => $email,
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
            'connected_by_user_id' => $u?->id,
        ]);
    }

    private function correo(?IntegrationToken $cuenta, string $asunto): EmailIngestion
    {
        return EmailIngestion::create([
            'message_id' => 'm-'.uniqid(),
            'integration_token_id' => $cuenta?->id,
            'from' => 'cliente@ejemplo.com',
            'to' => $cuenta?->account_email ?? 'compartida@proteccionlaboral.co',
            'subject' => $asunto,
            'body_text' => 'cuerpo',
            'received_at' => now(),
            'raw_payload' => [],
            'status' => EmailIngestion::STATUS_NEEDS_REVIEW,
        ]);
    }

    public function test_una_abogada_solo_ve_los_correos_de_su_cuenta(): void
    {
        $leidy = $this->usuario('abogado_interno');
        $carolina = $this->usuario('abogado_interno');

        $suyo = $this->correo($this->cuentaDe($leidy, 'leidy@proteccionlaboral.co'), 'Para Leidy');
        $ajeno = $this->correo($this->cuentaDe($carolina, 'carolina@proteccionlaboral.co'), 'Para Carolina');

        $visibles = EmailIngestion::query()->visiblePara($leidy)->pluck('id');

        $this->assertTrue($visibles->contains($suyo->id));
        $this->assertFalse($visibles->contains($ajeno->id), 'el correo de otra abogada no se ve');
    }

    public function test_el_director_ve_todas_las_bandejas(): void
    {
        $director = $this->usuario('director');
        $leidy = $this->usuario('abogado_interno');

        $this->correo($this->cuentaDe($leidy, 'leidy@proteccionlaboral.co'), 'Para Leidy');
        $this->correo($this->cuentaDe($director, 'carlos@proteccionlaboral.co'), 'Para Carlos');

        $this->assertSame(2, EmailIngestion::query()->visiblePara($director)->count());
    }

    /**
     * Los correos que entraron por la bandeja de automatización antes de este
     * cambio no tienen a quién atribuirse. Solo el director.
     */
    public function test_los_correos_heredados_sin_cuenta_solo_los_ve_el_director(): void
    {
        $leidy = $this->usuario('abogado_interno');
        $director = $this->usuario('director');
        $huerfano = $this->correo(null, 'De la bandeja vieja');

        $this->assertFalse(EmailIngestion::query()->visiblePara($leidy)->pluck('id')->contains($huerfano->id));
        $this->assertTrue(EmailIngestion::query()->visiblePara($director)->pluck('id')->contains($huerfano->id));
    }

    /**
     * Filtrar el listado no protege nada: la acción llega por id y un id se
     * adivina. Esta es la comprobación que cuenta.
     */
    public function test_no_se_puede_descartar_el_correo_de_otra_abogada(): void
    {
        $leidy = $this->usuario('abogado_interno');
        $carolina = $this->usuario('abogado_interno');
        $ajeno = $this->correo($this->cuentaDe($carolina, 'carolina@proteccionlaboral.co'), 'Para Carolina');

        $this->actingAs($leidy)
            ->post(route('admin.emails.review.discard', $ajeno))
            ->assertForbidden();

        $this->assertSame(EmailIngestion::STATUS_NEEDS_REVIEW, $ajeno->fresh()->status);
    }

    public function test_la_abogada_si_puede_descartar_el_suyo(): void
    {
        $leidy = $this->usuario('abogado_interno');
        $suyo = $this->correo($this->cuentaDe($leidy, 'leidy@proteccionlaboral.co'), 'Para Leidy');

        $this->actingAs($leidy)
            ->post(route('admin.emails.review.discard', $suyo))
            ->assertRedirect();

        $this->assertSame(EmailIngestion::STATUS_DISCARDED, $suyo->fresh()->status);
    }
}
