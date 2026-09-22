<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Lo que el cliente puede abrir desde su portal.
 *
 * Dos fallos a la vez: la casilla «visible para el cliente» se guardaba y el
 * portal no la leia (no listaba ningun documento), y la descarga solo miraba
 * que el documento fuera de uno de sus procesos, asi que cambiando el id en
 * la URL el cliente abria adjuntos de correo y borradores internos.
 */
class DocumentosDelPortalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Process $process;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $servicio = ServiceType::firstOrCreate(
            ['slug' => 'asesoria'],
            ['nombre' => 'Asesoría', 'descripcion' => 'test', 'modalidad' => 'permanente', 'es_activo' => true],
        );
        $this->client = Client::factory()->create(['portal_activo' => true]);
        $this->process = Process::factory()->create([
            'client_id' => $this->client->id,
            'service_type_id' => $servicio->id,
        ]);
    }

    private function documento(array $attrs): Document
    {
        $ruta = 'docs/'.uniqid().'.txt';
        Storage::disk('local')->put($ruta, 'contenido');

        return Document::create($attrs + [
            'nombre' => 'doc.txt',
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => 'otro',
            'mime' => 'text/plain',
            'generado_por_ia' => false,
            'visible_cliente' => false,
        ]);
    }

    private function descargar(Document $d)
    {
        return $this->actingAs($this->client, 'client')
            ->get(route('portal.documents.download', $d));
    }

    public function test_abre_lo_que_el_despacho_compartio(): void
    {
        $doc = $this->documento(['process_id' => $this->process->id, 'visible_cliente' => true]);

        $this->descargar($doc)->assertOk();
    }

    /** El agujero: un documento interno de SU proceso, pedido por id. */
    public function test_no_abre_un_documento_interno_de_su_propio_proceso(): void
    {
        $interno = $this->documento(['process_id' => $this->process->id, 'visible_cliente' => false]);

        $this->descargar($interno)->assertForbidden();
    }

    public function test_no_abre_documentos_de_otro_cliente_aunque_esten_compartidos(): void
    {
        $otro = Process::factory()->create(['service_type_id' => $this->process->service_type_id]);
        $ajeno = $this->documento(['process_id' => $otro->id, 'visible_cliente' => true]);

        $this->descargar($ajeno)->assertForbidden();
    }

    /** Lo compartido a nivel cliente (el contrato) antes no se podia descargar. */
    public function test_abre_los_documentos_generales_de_su_empresa_si_estan_compartidos(): void
    {
        $contrato = $this->documento(['client_id' => $this->client->id, 'visible_cliente' => true]);
        $noCompartido = $this->documento(['client_id' => $this->client->id, 'visible_cliente' => false]);

        $this->descargar($contrato)->assertOk();
        $this->descargar($noCompartido)->assertForbidden();
    }

    /** El acta sigue a su visita: si la visita se oculta, el acta tambien. */
    public function test_el_acta_de_una_visita_sigue_la_visibilidad_de_la_visita(): void
    {
        $visita = Visit::create([
            'process_id' => $this->process->id,
            'client_id' => $this->client->id,
            'tipo' => 'presencial',
            'fecha' => now()->toDateString(),
            'titulo' => 'Visita',
            'visible_cliente' => true,
        ]);
        $acta = $this->documento(['process_id' => $this->process->id, 'visit_id' => $visita->id, 'visible_cliente' => true]);

        $this->descargar($acta)->assertOk();

        $visita->update(['visible_cliente' => false]);
        $this->descargar($acta->fresh())->assertForbidden();
    }

    public function test_el_detalle_del_proceso_lista_solo_lo_compartido(): void
    {
        $this->documento(['process_id' => $this->process->id, 'visible_cliente' => true, 'nombre' => 'Concepto.pdf']);
        $this->documento(['process_id' => $this->process->id, 'visible_cliente' => false, 'nombre' => 'Borrador interno.docx']);
        $this->documento(['client_id' => $this->client->id, 'visible_cliente' => true, 'nombre' => 'Contrato.pdf']);

        $this->actingAs($this->client, 'client')
            ->get(route('portal.process', $this->process))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('process.documentos', 2)
                ->where('process.documentos', fn ($docs) => collect($docs)->pluck('nombre')->sort()->values()->all()
                    === ['Concepto.pdf', 'Contrato.pdf']));
    }
}
