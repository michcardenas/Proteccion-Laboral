<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\MensajeWord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * El cliente pedia «el documento» y no el texto suelto: cada mensaje del
 * despacho se descarga como Word con el logo.
 */
class MensajeEnWordTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Process $process;

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
        $this->client = Client::factory()->create(['portal_activo' => true, 'razon_social' => 'Empresa Demo S.A.S.']);
        $this->process = Process::factory()->create([
            'client_id' => $this->client->id,
            'abogado_lider_id' => $this->abogada->id,
            'codigo' => 'PL-DEMO-001',
        ]);
    }

    private function respuesta(bool $visible = true): Comment
    {
        return $this->process->comments()->create([
            'user_id' => $this->abogada->id,
            'visible_cliente' => $visible,
            'body' => "📧 Respuesta enviada a gerencia@demo.test\nAsunto: Re: Citación a descargos\n\nBuenos días,\n\nAdjuntamos **el acta** & los soportes.\n\nCordialmente,",
        ]);
    }

    /** @return array<string, string> partes del .docx */
    private function abrir(string $contenido): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmp, $contenido);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'El .docx no es un zip válido');
        $partes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $partes[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);

        return $partes;
    }

    public function test_el_word_lleva_cliente_asunto_y_cuerpo_sin_la_cabecera_del_correo(): void
    {
        $docx = app(MensajeWord::class)->generar($this->respuesta(), $this->process);
        $partes = $this->abrir($docx['contenido']);

        $this->assertSame('Re Citación a descargos - PL-DEMO-001.docx', $docx['nombre']);
        $this->assertArrayHasKey('word/media/logo.png', $partes);

        $xml = $partes['word/document.xml'];
        $this->assertNotFalse(simplexml_load_string($xml), 'document.xml no es XML válido');
        $this->assertStringContainsString('Empresa Demo S.A.S.', $xml);
        $this->assertStringContainsString('Asunto: Re: Citación a descargos', $xml);
        // **negrita** pasa a negrita de Word, sin asteriscos; & escapado.
        $this->assertStringContainsString('<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">el acta</w:t></w:r>', $xml);
        $this->assertStringContainsString(' &amp; los soportes.', $xml);
        $this->assertStringNotContainsString('**', $xml);
        // La línea del destinatario no es parte de la carta.
        $this->assertStringNotContainsString('Respuesta enviada', $xml);
        $this->assertStringNotContainsString('📧', $xml);
    }

    public function test_un_borrador_sin_cabecera_sale_entero(): void
    {
        $c = $this->process->comments()->create([
            'user_id' => $this->abogada->id, 'visible_cliente' => true, 'body' => 'Concepto sobre el caso.',
        ]);

        $docx = app(MensajeWord::class)->generar($c, $this->process);

        $this->assertSame('Comunicación - PL-DEMO-001.docx', $docx['nombre']);
        $this->assertStringContainsString('Concepto sobre el caso.', $this->abrir($docx['contenido'])['word/document.xml']);
    }

    public function test_un_borrador_ia_guardado_como_html_se_descarga_en_word(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $html = "<!doctype html><html><head><title>Contestación — PL-X</title></head><body>\n"
            .nl2br(e("# BORRADOR — CARTA\n\nSeñores **BOLUGA**:\n\n- Primer punto\n---\nFin")).'</body></html>';
        \Illuminate\Support\Facades\Storage::disk('local')->put('documents/p/b.html', $html);
        $doc = \App\Models\Document::create([
            'process_id' => $this->process->id, 'client_id' => $this->client->id,
            'nombre' => 'Contestación / respuesta — PL-X', 'ruta' => 'documents/p/b.html', 'disco' => 'local',
            'tipo' => 'escrito', 'mime' => 'text/html', 'generado_por_ia' => true, 'visible_cliente' => true,
        ]);

        $res = $this->actingAs($this->client, 'client')
            ->get(route('portal.documents.download', $doc))
            ->assertOk()
            ->assertHeader('Content-Type', MensajeWord::MIME)
            ->assertDownload('Contestación respuesta — PL-X.docx');

        $xml = $this->abrir($res->getContent())['word/document.xml'];
        $this->assertNotFalse(simplexml_load_string($xml));
        // El título va en negrita y sin «#»; el <title> no se repite; la viñeta queda.
        $this->assertStringContainsString('<w:b/><w:sz w:val="26"/><w:szCs w:val="26"/></w:rPr><w:t xml:space="preserve">BORRADOR — CARTA</w:t>', $xml);
        $this->assertStringNotContainsString('# ', $xml);
        $this->assertStringNotContainsString('PL-X', $xml);
        $this->assertStringContainsString('•  Primer punto', $xml);
        $this->assertStringNotContainsString('&lt;br', $xml);
    }

    public function test_el_cliente_descarga_su_mensaje_compartido(): void
    {
        $c = $this->respuesta();

        $this->actingAs($this->client, 'client')
            ->get(route('portal.messages.word', $c))
            ->assertOk()
            ->assertHeader('Content-Type', MensajeWord::MIME)
            ->assertDownload('Re Citación a descargos - PL-DEMO-001.docx');
    }

    public function test_el_cliente_no_descarga_lo_interno_ni_lo_ajeno(): void
    {
        $interno = $this->respuesta(false);
        $this->actingAs($this->client, 'client')
            ->get(route('portal.messages.word', $interno))
            ->assertForbidden();

        $otro = Client::factory()->create(['portal_activo' => true]);
        $this->actingAs($otro, 'client')
            ->get(route('portal.messages.word', $this->respuesta()))
            ->assertForbidden();
    }

    public function test_la_abogada_ve_el_mismo_word_desde_el_admin(): void
    {
        $this->actingAs($this->abogada)
            ->get(route('admin.comments.word', $this->respuesta(false)))
            ->assertOk()
            ->assertHeader('Content-Type', MensajeWord::MIME);
    }
}
