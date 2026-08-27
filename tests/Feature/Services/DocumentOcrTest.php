<?php

namespace Tests\Feature\Services;

use App\Models\AiGeneration;
use App\Models\Client;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Services\AiService;
use App\Services\DocumentOcr;
use App\Services\DriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * OCR de escaneados con la vista del modelo.
 *
 * En un despacho laboral lo escaneado es lo que importa —demandas radicadas,
 * sentencias, incapacidades— y era invisible para la IA: de 411 documentos, 70
 * eran imágenes sin una sola letra extraída.
 *
 * La API se falsea siempre: ninguna prueba puede gastar dinero.
 */
class DocumentOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function ai(string $respuesta = 'JUZGADO PRIMERO LABORAL. Radicado 2026-00123.'): AiService
    {
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('generateDraft')->andReturn([
            'text' => $respuesta,
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 200],
            'latencia_ms' => 3000,
        ]);
        $ai->shouldReceive('estimateCost')->andReturn(0.012);

        return $ai;
    }

    private function drive(?string $bytes = null): DriveService
    {
        $drive = Mockery::mock(DriveService::class);
        $drive->shouldReceive('downloadFile')->andReturn($bytes);

        return $drive;
    }

    private function imagen(array $overrides = []): Document
    {
        $client = Client::factory()->create();
        $ruta = 'clients/escaneo.png';
        Storage::disk('local')->put($ruta, 'bytes-de-la-imagen');

        return Document::create(array_merge([
            'client_id' => $client->id,
            'nombre' => 'demanda escaneada.png',
            'ruta' => $ruta,
            'disco' => 'local',
        ], $overrides));
    }

    public function test_lee_una_imagen_del_disco_y_guarda_el_texto(): void
    {
        $doc = $this->imagen();

        $texto = (new DocumentOcr($this->ai(), $this->drive()))->read($doc);

        $this->assertStringContainsString('Radicado 2026-00123', $texto);
        $this->assertStringContainsString('Radicado 2026-00123', $doc->fresh()->texto_extraido);
        $this->assertNotNull($doc->fresh()->texto_extraido_at);
    }

    /**
     * Se guarda en `texto_extraido` y no en un campo propio: para la ficha, el
     * resumen y el contexto del proceso, un escaneado leído tiene que ser un
     * documento con texto como cualquier otro.
     */
    public function test_el_texto_queda_donde_lo_busca_el_resto_del_sistema(): void
    {
        $doc = $this->imagen();

        (new DocumentOcr($this->ai(), $this->drive()))->read($doc);

        $this->assertNotEmpty(
            Document::whereNotNull('texto_extraido')->where('texto_extraido', '!=', '')->find($doc->id)
        );
    }

    /** Los que quedaron como enlace se descargan de Drive, no del disco. */
    public function test_descarga_de_drive_los_que_estan_como_enlace(): void
    {
        $doc = $this->imagen([
            'disco' => 'gdrive',
            'ruta' => 'https://drive.google.com/file/d/abc/view',
            'drive_file_id' => 'abc',
        ]);

        $texto = (new DocumentOcr($this->ai(), $this->drive('bytes-desde-drive')))->read($doc);

        $this->assertStringContainsString('Radicado', $texto);
    }

    public function test_si_drive_no_devuelve_el_archivo_no_se_llama_a_la_api(): void
    {
        $doc = $this->imagen([
            'disco' => 'gdrive',
            'ruta' => 'https://drive.google.com/file/d/abc/view',
            'drive_file_id' => 'abc',
        ]);

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('generateDraft');

        $this->assertNull((new DocumentOcr($ai, $this->drive(null)))->read($doc));
    }

    /** Un PDF o un Word no van por aquí: para eso está DocumentTextExtractor. */
    public function test_ignora_lo_que_no_es_imagen(): void
    {
        $doc = $this->imagen(['nombre' => 'contrato.pdf']);

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('generateDraft');

        $this->assertNull((new DocumentOcr($ai, $this->drive()))->read($doc));
    }

    /** Lo que ya tiene texto por otra vía no se vuelve a pagar. */
    public function test_no_toca_lo_que_ya_tiene_texto(): void
    {
        $doc = $this->imagen(['texto_extraido' => 'ya se leyó', 'texto_extraido_at' => now()]);

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('generateDraft');

        $this->assertNull((new DocumentOcr($ai, $this->drive()))->read($doc));
    }

    /**
     * Una foto de una fachada no es un fallo: es una imagen sin texto. Se marca
     * como intentada para no volver a pagar por ella en cada corrida.
     */
    public function test_una_imagen_sin_texto_queda_marcada_como_intentada(): void
    {
        $doc = $this->imagen();

        $this->assertNull((new DocumentOcr($this->ai('[SIN TEXTO LEGIBLE]'), $this->drive()))->read($doc));

        $fresco = $doc->fresh();
        $this->assertSame('', $fresco->texto_extraido);
        $this->assertNotNull($fresco->texto_extraido_at, 'la marca evita reintentarla en cada corrida');
    }

    /** Una imagen enorme se rechaza antes de gastar la llamada. */
    public function test_rechaza_las_imagenes_demasiado_grandes(): void
    {
        $doc = $this->imagen();
        Storage::disk('local')->put($doc->ruta, str_repeat('x', DocumentOcr::MAX_BYTES + 10));

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('generateDraft');

        $this->assertNull((new DocumentOcr($ai, $this->drive()))->read($doc->fresh()));
        $this->assertSame('error', AiGeneration::where('contexto_tipo', Document::class)->firstOrFail()->estado);
    }

    /** Un escaneado ilegible no puede tumbar el lote. */
    public function test_un_fallo_no_lanza_y_queda_registrado(): void
    {
        $doc = $this->imagen();

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('generateDraft')->andThrow(new \RuntimeException('529 overloaded'));

        $this->assertNull((new DocumentOcr($ai, $this->drive()))->read($doc));
        $this->assertSame('error', AiGeneration::where('contexto_tipo', Document::class)->firstOrFail()->estado);
    }

    public function test_el_gasto_queda_registrado(): void
    {
        $doc = $this->imagen();

        (new DocumentOcr($this->ai(), $this->drive()))->read($doc);

        $g = AiGeneration::where('contexto_tipo', Document::class)->firstOrFail();
        $this->assertSame('ok', $g->estado);
        $this->assertStringStartsWith('document_ocr:', $g->prompt);
        $this->assertEqualsWithDelta(0.012, (float) $g->costo_usd, 0.0001);
    }

    /**
     * El filtro que evita envenenar la ficha.
     *
     * Un logo leido mete «ZONAMEDICA IPS · Salud de verdad! · ISO 9001» en el
     * texto del documento, de ahi pasa a su resumen y de ahi al conocimiento
     * del cliente. Es peor que dejarlo vacio.
     */
    public function test_reconoce_los_logos_incrustados_en_correos(): void
    {
        $ocr = new DocumentOcr($this->ai(), $this->drive());
        $ingesta = EmailIngestion::create([
            'message_id' => 'abc123',
            'from' => 'alguien@cliente.co',
            'to' => 'automatizacion@proteccionlaboral.co',
            'subject' => 'Consulta',
            'body_text' => 'Cuerpo del correo.',
            'received_at' => now(),
            'raw_payload' => ['attachments' => []],
        ]);

        foreach (['Outlook-uj1pcie4.png', 'image001.png', 'image.png'] as $nombre) {
            $doc = $this->imagen(['nombre' => $nombre, 'email_ingestion_id' => $ingesta->id]);
            $this->assertTrue($ocr->pareceFirmaDeCorreo($doc), $nombre.' es un logo de firma');
        }
    }

    /** Un escaneado que llega por correo conserva su nombre y SI se lee. */
    public function test_un_adjunto_con_nombre_propio_no_es_una_firma(): void
    {
        $ingesta = EmailIngestion::create([
            'message_id' => 'def456',
            'from' => 'alguien@cliente.co',
            'to' => 'automatizacion@proteccionlaboral.co',
            'subject' => 'Incapacidad',
            'body_text' => 'Cuerpo del correo.',
            'received_at' => now(),
            'raw_payload' => ['attachments' => []],
        ]);
        $doc = $this->imagen(['nombre' => 'incapacidad juan perez.jpg', 'email_ingestion_id' => $ingesta->id]);

        $this->assertFalse((new DocumentOcr($this->ai(), $this->drive()))->pareceFirmaDeCorreo($doc));
    }

    /** Una imagen de Drive nunca es una firma de correo, se llame como se llame. */
    public function test_lo_que_viene_de_drive_no_se_confunde_con_una_firma(): void
    {
        $doc = $this->imagen(['nombre' => 'image.png', 'disco' => 'gdrive', 'drive_file_id' => 'x']);

        $this->assertFalse((new DocumentOcr($this->ai(), $this->drive()))->pareceFirmaDeCorreo($doc));
    }
}
