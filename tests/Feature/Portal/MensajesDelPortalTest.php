<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Comentarios compartidos con el cliente (respuestas de correo, borradores
 * IA). La casilla «visible para el cliente» se guardaba y el portal no los
 * mostraba: en prod habia una contestacion marcada visible que el cliente
 * nunca vio.
 */
class MensajesDelPortalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Process $process;

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
        $this->client = Client::factory()->create(['portal_activo' => true]);
        $this->process = Process::factory()->create([
            'client_id' => $this->client->id,
            'abogado_lider_id' => $this->abogada->id,
        ]);
    }

    private function comentario(bool $visible, string $body): Comment
    {
        return $this->process->comments()->create([
            'user_id' => $this->abogada->id,
            'body' => $body,
            'visible_cliente' => $visible,
        ]);
    }

    public function test_el_cliente_ve_lo_compartido_y_no_lo_interno(): void
    {
        $this->comentario(true, 'Respuesta para el cliente');
        $this->comentario(false, 'Nota interna');

        $this->actingAs($this->client, 'client')
            ->get(route('portal.process', $this->process))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('process.mensajes', 1)
                ->where('process.mensajes.0.body', 'Respuesta para el cliente')
                ->where('process.mensajes.0.autor', $this->abogada->name));
    }

    public function test_la_abogada_comparte_y_deja_de_compartir_un_comentario(): void
    {
        $c = $this->comentario(false, 'Contestación');

        $this->actingAs($this->abogada)
            ->patch(route('admin.comments.visibility', $c), ['visible_cliente' => true])
            ->assertRedirect();
        $this->assertTrue($c->fresh()->visible_cliente);

        $this->actingAs($this->abogada)
            ->patch(route('admin.comments.visibility', $c), ['visible_cliente' => false])
            ->assertRedirect();
        $this->assertFalse($c->fresh()->visible_cliente);
    }

    public function test_quien_no_comparte_documentos_tampoco_comparte_comentarios(): void
    {
        $c = $this->comentario(false, 'Contestación');
        $contador = tap(User::factory()->create())->assignRole('contador');

        $this->actingAs($contador)
            ->patch(route('admin.comments.visibility', $c), ['visible_cliente' => true])
            ->assertForbidden();
        $this->assertFalse($c->fresh()->visible_cliente);
    }
}
