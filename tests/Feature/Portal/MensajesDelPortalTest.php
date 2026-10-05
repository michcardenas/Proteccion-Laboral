<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function adjunto(Comment $c, bool $visible): Document
    {
        return Document::create([
            'process_id' => $this->process->id,
            'client_id' => $this->client->id,
            'comment_id' => $c->id,
            'nombre' => 'Concepto.docx',
            'ruta' => 'documents/x.docx',
            'disco' => 'local',
            'tipo' => 'comunicacion',
            'visible_cliente' => $visible,
        ]);
    }

    public function test_el_mensaje_lleva_su_word_para_descargar(): void
    {
        $c = $this->comentario(true, 'Le enviamos el concepto');
        $doc = $this->adjunto($c, true);

        $this->actingAs($this->client, 'client')
            ->get(route('portal.process', $this->process))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('process.mensajes.0.adjuntos', 1)
                ->where('process.mensajes.0.adjuntos.0.nombre', 'Concepto.docx')
                ->where('process.mensajes.0.adjuntos.0.url', route('portal.documents.download', $doc->id)));
    }

    public function test_compartir_el_mensaje_comparte_sus_adjuntos(): void
    {
        $c = $this->comentario(false, 'Contestación');
        $doc = $this->adjunto($c, false);

        $this->actingAs($this->abogada)
            ->patch(route('admin.comments.visibility', $c), ['visible_cliente' => true]);
        $this->assertTrue($doc->fresh()->visible_cliente);

        $this->actingAs($this->abogada)
            ->patch(route('admin.comments.visibility', $c), ['visible_cliente' => false]);
        $this->assertFalse($doc->fresh()->visible_cliente);
    }

    public function test_adjuntar_a_un_mensaje_ya_enviado_el_word_que_se_mando_por_gmail(): void
    {
        Storage::fake('local');
        $c = $this->comentario(true, 'Le enviamos el concepto');

        $this->actingAs($this->abogada)
            ->post(route('admin.comments.documents.store', $c), [
                'archivos' => [UploadedFile::fake()->create('Concepto.docx', 30)],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $doc = Document::sole();
        $this->assertSame($c->id, $doc->comment_id);
        $this->assertSame($this->process->id, $doc->process_id);
        // Hereda la visibilidad del mensaje: el cliente lo ve ya.
        $this->assertTrue($doc->visible_cliente);
        Storage::disk('local')->assertExists($doc->ruta);

        $this->actingAs($this->client, 'client')
            ->get(route('portal.process', $this->process))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('process.mensajes.0.adjuntos.0.nombre', 'Concepto.docx'));
    }

    public function test_adjuntar_a_un_mensaje_interno_lo_deja_oculto(): void
    {
        Storage::fake('local');
        $c = $this->comentario(false, 'Nota interna');

        $this->actingAs($this->abogada)
            ->post(route('admin.comments.documents.store', $c), [
                'archivos' => [UploadedFile::fake()->create('borrador.docx', 30)],
            ]);

        $this->assertFalse(Document::sole()->visible_cliente);
    }

    public function test_adjuntar_rechaza_formatos_no_admitidos(): void
    {
        Storage::fake('local');
        $c = $this->comentario(true, 'x');

        $this->actingAs($this->abogada)
            ->post(route('admin.comments.documents.store', $c), [
                'archivos' => [UploadedFile::fake()->create('script.exe', 10)],
            ])
            ->assertSessionHasErrors(['archivos.0' => 'Solo se admiten Word, PDF, Excel, imágenes o texto (el archivo no lo es o está dañado).']);

        $this->assertSame(0, Document::count());
    }

    public function test_quien_no_sube_documentos_no_adjunta(): void
    {
        $c = $this->comentario(true, 'x');
        $sinRol = User::factory()->create();

        $this->actingAs($sinRol)
            ->post(route('admin.comments.documents.store', $c), [
                'archivos' => [UploadedFile::fake()->create('a.docx', 10)],
            ])
            ->assertForbidden();
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
