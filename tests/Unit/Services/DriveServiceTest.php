<?php

namespace Tests\Unit\Services;

use App\Console\Commands\DriveMapClients;
use App\Models\Client;
use App\Services\DriveService;
use App\Services\GmailService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Helpers puros de la integración con Drive: no tocan la red ni la base de datos.
 */
class DriveServiceTest extends TestCase
{
    protected function service(): DriveService
    {
        return new DriveService(new GmailService);
    }

    public function test_normaliza_un_archivo_de_la_api(): void
    {
        $file = $this->service()->normalizeFile([
            'id' => 'abc123',
            'name' => 'Contrato Zonamédica.pdf',
            'mimeType' => 'application/pdf',
            'modifiedTime' => '2026-08-10T15:04:05.000Z',
            'size' => '2048',
            'webViewLink' => 'https://drive.google.com/file/d/abc123/view',
        ]);

        $this->assertSame('abc123', $file['id']);
        $this->assertSame('Contrato Zonamédica.pdf', $file['name']);
        $this->assertSame(2048, $file['size']);
        $this->assertFalse($file['is_folder']);
        $this->assertSame('2026-08-10', $file['modified_at']->toDateString());
    }

    public function test_reconoce_las_carpetas(): void
    {
        $carpeta = $this->service()->normalizeFile([
            'id' => 'f1', 'name' => 'GPT SEGUROS', 'mimeType' => DriveService::FOLDER_MIME,
        ]);

        $this->assertTrue($carpeta['is_folder']);
        $this->assertNull($carpeta['size']);
    }

    public function test_los_nativos_de_google_se_exportan_con_otra_extension(): void
    {
        $service = $this->service();

        $doc = $service->normalizeFile(['id' => 'd', 'name' => 'Plan de trabajo', 'mimeType' => 'application/vnd.google-apps.document']);
        $hoja = $service->normalizeFile(['id' => 'h', 'name' => 'Control', 'mimeType' => 'application/vnd.google-apps.spreadsheet']);

        $this->assertSame('docx', $service->targetExtension($doc));
        $this->assertSame('csv', $service->targetExtension($hoja));
        $this->assertTrue($service->esLegible($doc));
        $this->assertTrue($service->esLegible($hoja));
    }

    public function test_distingue_los_archivos_que_la_ia_puede_leer(): void
    {
        $service = $this->service();

        $pdf = $service->normalizeFile(['id' => '1', 'name' => 'demanda.pdf', 'mimeType' => 'application/pdf']);
        $zip = $service->normalizeFile(['id' => '2', 'name' => 'soportes.zip', 'mimeType' => 'application/zip']);

        $this->assertTrue($service->esLegible($pdf));
        $this->assertFalse($service->esLegible($zip));
    }

    public function test_respeta_el_tope_de_tamano(): void
    {
        config()->set('drive.max_file_mb', 1);
        $service = $this->service();

        $grande = $service->normalizeFile(['id' => '1', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => (string) (2 * 1024 * 1024)]);
        $chico = $service->normalizeFile(['id' => '2', 'name' => 'b.pdf', 'mimeType' => 'application/pdf', 'size' => '1024']);
        $nativo = $service->normalizeFile(['id' => '3', 'name' => 'c', 'mimeType' => 'application/vnd.google-apps.document']);

        $this->assertTrue($service->excedeTamano($grande));
        $this->assertFalse($service->excedeTamano($chico));
        $this->assertFalse($service->excedeTamano($nativo), 'los nativos de Google no reportan tamaño');
    }

    // ------------------------------------------------------------------
    // Emparejado carpeta → cliente (comando drive:map-clients)
    // ------------------------------------------------------------------

    protected function clientes(): Collection
    {
        return collect([
            new Client(['razon_social' => 'GPT SEGUROS S.A.S.', 'nit' => '901555888']),
            new Client(['razon_social' => 'Zonamédica', 'nit' => '900123456']),
            new Client(['razon_social' => 'Colchones Resplandor', 'nit' => '890702700']),
        ]);
    }

    public function test_empareja_la_carpeta_por_nit(): void
    {
        $match = (new DriveMapClients)->sugerirCliente('CLIENTE 901555888 - carpeta', $this->clientes());

        $this->assertSame('GPT SEGUROS S.A.S.', $match['cliente']->razon_social);
        $this->assertSame('NIT', $match['motivo']);
        $this->assertSame(1.0, $match['score']);
    }

    public function test_empareja_la_carpeta_por_razon_social_ignorando_tildes_y_sufijos(): void
    {
        $match = (new DriveMapClients)->sugerirCliente('Zonamedica', $this->clientes());

        $this->assertSame('Zonamédica', $match['cliente']->razon_social);
        $this->assertGreaterThanOrEqual(0.9, $match['score']);
    }

    public function test_una_carpeta_ajena_queda_con_confianza_baja(): void
    {
        $match = (new DriveMapClients)->sugerirCliente('Plantillas internas del despacho', $this->clientes());

        $this->assertLessThan(0.6, $match['score'], 'no debería proponerse ningún cliente por encima del umbral');
    }
}
