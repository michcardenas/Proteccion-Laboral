<?php

namespace Tests\Feature\Services;

use App\Jobs\RegenerateClientKnowledge;
use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Services\DocumentTextExtractor;
use App\Services\DriveKnowledgeSync;
use App\Services\DriveService;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Sincronización Drive → documents. El DriveService se mockea parcialmente: los helpers
 * puros corren de verdad y solo se falsean las dos llamadas que tocan la red
 * (listFilesRecursive y downloadFile).
 */
class DriveKnowledgeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        // Se fija a propósito: sin esto el test leía `DRIVE_STORE_FILES` del
        // `.env` de quien lo corriera y pasaba o fallaba según la máquina. En
        // este repo está en `false`, así que la aserción de `disco='local'`
        // llevaba semanas roja sin que el código tuviera nada malo. Cada rama
        // tiene ahora su prueba y ninguna depende del entorno.
        config()->set('drive.store_files', true);
    }

    protected function archivo(array $overrides = []): array
    {
        return array_merge([
            'id' => 'file-1',
            'name' => 'contrato.txt',
            'mime_type' => 'text/plain',
            'modified_at' => Carbon::parse('2026-08-01 10:00:00'),
            'size' => 100,
            'web_view_link' => 'https://drive.google.com/file/d/file-1/view',
            'is_folder' => false,
            'path' => '',
        ], $overrides);
    }

    /**
     * @param  array<int, array>  $archivos
     * @param  array<string, string>  $contenidos  drive_file_id => contenido descargado
     */
    protected function sync(array $archivos, array $contenidos = []): DriveKnowledgeSync
    {
        $drive = Mockery::mock(DriveService::class, [new GmailService])->makePartial();
        $drive->shouldReceive('listFilesRecursive')->andReturn($archivos);
        $drive->shouldReceive('downloadFile')->andReturnUsing(
            fn (array $file) => $contenidos[$file['id']] ?? null
        );

        return new DriveKnowledgeSync($drive, new DocumentTextExtractor);
    }

    protected function cliente(): Client
    {
        return Client::factory()->create([
            'drive_folder_id' => 'folder-abc',
            'drive_folder_name' => 'CLIENTE DEMO',
        ]);
    }

    public function test_crea_los_documentos_y_extrae_su_texto(): void
    {
        $client = $this->cliente();

        $stats = $this->sync(
            [$this->archivo(['id' => 'f1', 'name' => 'Contrato de prestación.txt'])],
            ['f1' => 'Cláusula primera: el objeto del contrato es la asesoría laboral.'],
        )->syncClient($client);

        $this->assertSame(1, $stats['nuevos']);

        $doc = Document::where('drive_file_id', 'f1')->firstOrFail();
        $this->assertSame($client->id, $doc->client_id);
        $this->assertSame('local', $doc->disco);
        $this->assertSame('contrato', $doc->tipo, 'el tipo se deduce del nombre');
        $this->assertStringContainsString('asesoría laboral', $doc->texto_extraido);
        Storage::disk('local')->assertExists($doc->ruta);
    }

    public function test_el_texto_queda_cacheado_y_no_se_reintenta(): void
    {
        $client = $this->cliente();

        $this->sync([$this->archivo(['id' => 'f1'])], ['f1' => 'contenido legible'])->syncClient($client);

        $doc = Document::where('drive_file_id', 'f1')->firstOrFail();

        // Si texto_extraido_at quedara por detrás de updated_at, el extractor daría el
        // texto por obsoleto y volvería a leer el archivo en cada generación.
        $this->assertNotNull($doc->texto_extraido_at);
        $this->assertTrue($doc->texto_extraido_at->greaterThanOrEqualTo($doc->updated_at));
        $this->assertSame('contenido legible', (new DocumentTextExtractor)->extractFromDocument($doc->fresh()));
    }

    public function test_no_vuelve_a_descargar_lo_que_no_cambio_en_drive(): void
    {
        $client = $this->cliente();
        $archivos = [$this->archivo(['id' => 'f1'])];

        $this->sync($archivos, ['f1' => 'texto'])->syncClient($client);
        $stats = $this->sync($archivos, ['f1' => 'texto'])->syncClient($client);

        $this->assertSame(0, $stats['nuevos']);
        $this->assertSame(1, $stats['omitidos']);
        $this->assertSame(1, Document::where('client_id', $client->id)->count());
    }

    public function test_reprocesa_cuando_el_archivo_cambio_en_drive(): void
    {
        $client = $this->cliente();

        $this->sync([$this->archivo(['id' => 'f1'])], ['f1' => 'version vieja'])->syncClient($client);

        $stats = $this->sync(
            [$this->archivo(['id' => 'f1', 'modified_at' => Carbon::parse('2026-08-15 09:00:00')])],
            ['f1' => 'version nueva'],
        )->syncClient($client);

        $this->assertSame(1, $stats['actualizados']);
        $this->assertSame('version nueva', Document::where('drive_file_id', 'f1')->value('texto_extraido'));
    }

    public function test_los_archivos_ilegibles_quedan_como_enlace_a_drive(): void
    {
        $client = $this->cliente();

        $stats = $this->sync([
            $this->archivo(['id' => 'f2', 'name' => 'soportes.zip', 'mime_type' => 'application/zip']),
        ])->syncClient($client);

        $doc = Document::where('drive_file_id', 'f2')->firstOrFail();
        $this->assertSame('gdrive', $doc->disco);
        $this->assertStringStartsWith('https://drive.google.com/', $doc->ruta);
        $this->assertSame('', $doc->texto_extraido);
        $this->assertSame(1, $stats['sin_texto']);
    }

    /**
     * La rama que de verdad se usa en este repo (`DRIVE_STORE_FILES=false`),
     * para no duplicar en disco lo que ya vive en Drive.
     *
     * El documento queda como ENLACE, y eso tiene una consecuencia que hay que
     * sostener: un documento con `disco='gdrive'` guarda una URL, y
     * `extractFromDocument` no descarga nada, así que **no se puede releer
     * después**. El texto que se cachea aquí es el único que va a existir; si
     * este camino dejara de guardarlo, la ficha del cliente y el contexto de la
     * IA se quedarían vacíos sin que fallara nada.
     */
    public function test_con_store_files_desactivado_solo_conserva_el_texto(): void
    {
        config()->set('drive.store_files', false);
        $client = $this->cliente();

        $this->sync([$this->archivo(['id' => 'f1'])], ['f1' => 'solo el texto importa'])->syncClient($client);

        $doc = Document::where('drive_file_id', 'f1')->firstOrFail();
        $this->assertSame('gdrive', $doc->disco);
        $this->assertSame('https://drive.google.com/file/d/file-1/view', $doc->ruta, 'la ruta es el enlace a Drive');
        $this->assertSame('solo el texto importa', $doc->texto_extraido);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_elimina_los_documentos_que_ya_no_estan_en_drive_sin_tocar_los_subidos_a_mano(): void
    {
        $client = $this->cliente();

        $this->sync([$this->archivo(['id' => 'f1'])], ['f1' => 'texto'])->syncClient($client);

        $aMano = Document::create([
            'client_id' => $client->id,
            'nombre' => 'subido desde la app.pdf',
            'ruta' => "clients/client_{$client->id}/manual.pdf",
            'disco' => 'local',
            'tipo' => 'otro',
        ]);

        $stats = $this->sync([])->syncClient($client);

        $this->assertSame(1, $stats['eliminados']);
        $this->assertSoftDeleted('documents', ['drive_file_id' => 'f1']);
        $this->assertNotSoftDeleted('documents', ['id' => $aMano->id]);
    }

    public function test_regenera_la_ficha_solo_cuando_hubo_cambios(): void
    {
        $client = $this->cliente();
        $archivos = [$this->archivo(['id' => 'f1'])];

        $this->sync($archivos, ['f1' => 'texto'])->syncClient($client);
        Queue::assertPushed(RegenerateClientKnowledge::class, 1);

        // Segunda corrida sin cambios: no se gasta API.
        $this->sync($archivos, ['f1' => 'texto'])->syncClient($client);
        Queue::assertPushed(RegenerateClientKnowledge::class, 1);
    }

    public function test_no_regenera_la_ficha_si_se_pide_omitirla(): void
    {
        $client = $this->cliente();

        $this->sync([$this->archivo(['id' => 'f1'])], ['f1' => 'texto'])
            ->syncClient($client, ['regenerar_ficha' => false]);

        Queue::assertNotPushed(RegenerateClientKnowledge::class);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $client = $this->cliente();

        $stats = $this->sync([$this->archivo(['id' => 'f1'])], ['f1' => 'texto'])
            ->syncClient($client, ['dry_run' => true]);

        $this->assertSame(1, $stats['nuevos']);
        $this->assertSame(0, Document::count());
        $this->assertNull($client->fresh()->drive_synced_at);
    }

    public function test_falla_si_el_cliente_no_tiene_carpeta_mapeada(): void
    {
        $client = Client::factory()->create(['drive_folder_id' => null]);

        $this->expectException(\RuntimeException::class);

        $this->sync([])->syncClient($client);
    }

    /**
     * Lo que hace que el contexto se mantenga solo: un archivo que aparece en la
     * carpeta de un proceso se ata a ese proceso sin que nadie se lo diga.
     */
    public function test_ata_el_documento_al_proceso_de_su_carpeta(): void
    {
        $client = $this->cliente();
        $servicio = ServiceType::create([
            'nombre' => 'Asesoria', 'slug' => 'asesoria', 'modalidad' => 'judicial', 'es_activo' => true,
        ]);
        $proceso = Process::create([
            'client_id' => $client->id,
            'service_type_id' => $servicio->id,
            'codigo' => 'PL-CARPETA-1',
            'titulo' => '16 COMPRAVENTA GARCES',
            'drive_folder' => '16 COMPRAVENTA GARCES',
            'estado' => 'en_curso',
            'fecha_apertura' => now()->toDateString(),
        ]);

        $this->sync(
            [$this->archivo(['id' => 'f1', 'name' => 'escritura.txt', 'path' => '16 COMPRAVENTA GARCES/ESCRITURA 4300'])],
            ['f1' => 'texto de la escritura'],
        )->syncClient($client);

        $this->assertSame($proceso->id, Document::where('drive_file_id', 'f1')->firstOrFail()->process_id);
    }

    /** Un archivo de una carpeta que nadie convirtio en proceso se queda del cliente. */
    public function test_sin_proceso_para_esa_carpeta_el_documento_queda_del_cliente(): void
    {
        $client = $this->cliente();

        $this->sync(
            [$this->archivo(['id' => 'f1', 'name' => 'suelto.txt', 'path' => 'CARPETA SIN PROCESO'])],
            ['f1' => 'contenido'],
        )->syncClient($client);

        $this->assertNull(Document::where('drive_file_id', 'f1')->firstOrFail()->process_id);
    }

    /**
     * Una empresa llevada por dos abogadas tiene carpeta bajo cada una. Durante
     * la importación del despacho eso pisaba el único campo `drive_folder_id`
     * del cliente, y la primera carpeta —con sus documentos— desaparecía sin
     * que nada avisara. Las carpetas de más viven ahora en su propia tabla y el
     * sync las recorre todas.
     */
    public function test_sincroniza_las_carpetas_de_las_dos_abogadas(): void
    {
        $client = $this->cliente();
        $client->carpetasDriveExtra()->create([
            'drive_folder_id' => 'folder-xyz',
            'drive_folder_name' => 'DR JUAN DAVID / SUMITOR',
        ]);

        $porCarpeta = [
            'folder-abc' => [$this->archivo(['id' => 'f1', 'name' => 'demanda.txt'])],
            'folder-xyz' => [$this->archivo(['id' => 'f2', 'name' => 'contestacion.txt'])],
        ];

        $drive = Mockery::mock(DriveService::class, [new GmailService])->makePartial();
        $drive->shouldReceive('listFilesRecursive')
            ->andReturnUsing(fn (string $folderId) => $porCarpeta[$folderId] ?? []);
        $drive->shouldReceive('downloadFile')->andReturnUsing(
            fn (array $file) => $file['id'] === 'f1' ? 'texto de la demanda' : 'texto de la contestación'
        );

        $stats = (new DriveKnowledgeSync($drive, new DocumentTextExtractor))->syncClient($client);

        $this->assertSame(2, $stats['nuevos'], 'los documentos de ambas carpetas');
        $this->assertSame(0, $stats['eliminados'], 'ninguna carpeta se da por ausente');

        // Y sobre todo: el de la segunda abogada no se pierde.
        $this->assertDatabaseHas('documents', ['drive_file_id' => 'f2', 'client_id' => $client->id]);
    }

    /** El tope de archivos es del cliente, no de cada carpeta. */
    public function test_el_tope_de_archivos_no_se_duplica_por_tener_dos_carpetas(): void
    {
        config()->set('drive.max_files_per_client', 3);

        $client = $this->cliente();
        $client->carpetasDriveExtra()->create(['drive_folder_id' => 'folder-xyz']);

        $lote = fn (string $prefijo) => array_map(
            fn (int $i) => $this->archivo(['id' => "{$prefijo}{$i}", 'name' => "doc{$i}.txt"]),
            range(1, 4),
        );

        $drive = Mockery::mock(DriveService::class, [new GmailService])->makePartial();
        $drive->shouldReceive('listFilesRecursive')
            ->andReturnUsing(fn (string $folderId) => $lote($folderId === 'folder-abc' ? 'a' : 'b'));
        $drive->shouldReceive('downloadFile')->andReturn('contenido');

        $stats = (new DriveKnowledgeSync($drive, new DocumentTextExtractor))->syncClient($client);

        $this->assertSame(3, $stats['nuevos']);
    }
}
