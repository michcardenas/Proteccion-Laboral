<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeProcess(string $codigo = 'PL-REV-1'): Process
    {
        $serviceType = ServiceType::firstOrCreate(
            ['slug' => 'servicio-test'],
            ['nombre' => 'Servicio test', 'descripcion' => 'x', 'modalidad' => 'por_evento', 'es_activo' => true],
        );

        return Process::factory()->create([
            'client_id' => Client::factory()->create()->id,
            'service_type_id' => $serviceType->id,
            'codigo' => $codigo,
            'titulo' => 'Proceso para revisión',
        ]);
    }

    protected function makeNeedsReview(string $messageId = 'rev-1'): EmailIngestion
    {
        return EmailIngestion::create([
            'message_id' => $messageId,
            'from' => 'Desconocido <quien@ejemplo.com>',
            'to' => 'automatizacion@proteccionlaboral.co',
            'subject' => 'Correo sin enrutar',
            'received_at' => now(),
            'raw_payload' => [],
            'body_text' => 'No se pudo clasificar con certeza.',
            'status' => EmailIngestion::STATUS_NEEDS_REVIEW,
            'process_id' => null,
            'ai_classification' => ['action' => 'requiere_revision_humana', 'confidence' => 0.4, 'summary' => 'Dudoso'],
        ]);
    }

    public function test_index_lista_solo_needs_review(): void
    {
        $director = User::factory()->create(['is_active' => true]);
        $director->assignRole('director');

        $this->makeNeedsReview('rev-1');

        // Un correo ya procesado NO debe aparecer.
        EmailIngestion::create([
            'message_id' => 'done-1',
            'from' => 'x@y.com',
            'to' => 'z@y.com',
            'subject' => 'Ya procesado',
            'received_at' => now(),
            'raw_payload' => [],
            'body_text' => 'Correo ya enrutado.',
            'status' => EmailIngestion::STATUS_PROCESSED,
        ]);

        $this->actingAs($director)
            ->get(route('admin.emails.review.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Emails/Review')
                ->where('correos.total', 1)
            );
    }

    public function test_index_requiere_permiso(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('abogado_interno'); // NO tiene emails.review

        $this->actingAs($user)
            ->get(route('admin.emails.review.index'))
            ->assertForbidden();
    }

    public function test_assign_enlaza_proceso_crea_comentario_y_procesa(): void
    {
        $director = User::factory()->create(['is_active' => true]);
        $director->assignRole('director');

        $process = $this->makeProcess();
        $email = $this->makeNeedsReview();

        $this->actingAs($director)
            ->post(route('admin.emails.review.assign', $email), ['process_id' => $process->id])
            ->assertRedirect();

        $this->assertDatabaseHas('email_ingestions', [
            'id' => $email->id,
            'process_id' => $process->id,
            'status' => EmailIngestion::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('comments', [
            'commentable_type' => Process::class,
            'commentable_id' => $process->id,
            'email_ingestion_id' => $email->id,
        ]);
    }

    public function test_assign_falla_si_el_correo_ya_no_esta_en_revision(): void
    {
        $director = User::factory()->create(['is_active' => true]);
        $director->assignRole('director');

        $process = $this->makeProcess();
        $email = $this->makeNeedsReview();
        $email->forceFill(['status' => EmailIngestion::STATUS_PROCESSED])->save();

        $this->actingAs($director)
            ->post(route('admin.emails.review.assign', $email), ['process_id' => $process->id])
            ->assertStatus(422);
    }

    public function test_discard_marca_descartado_sin_proceso(): void
    {
        $director = User::factory()->create(['is_active' => true]);
        $director->assignRole('director');

        $email = $this->makeNeedsReview();

        $this->actingAs($director)
            ->post(route('admin.emails.review.discard', $email))
            ->assertRedirect();

        $this->assertDatabaseHas('email_ingestions', [
            'id' => $email->id,
            'status' => EmailIngestion::STATUS_DISCARDED,
            'process_id' => null,
        ]);
    }
}
