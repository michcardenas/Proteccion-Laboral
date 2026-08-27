<?php

namespace Tests\Feature\Console;

use App\Models\Client;
use App\Models\Document;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fusionar dos fichas del mismo cliente.
 *
 * Pasa cuando una empresa tiene carpeta bajo dos abogadas en Drive y se importa
 * dos veces (VISUALED y VISUAL PUBLICIDAD, mismo NIT). Lo que no puede pasar
 * bajo ningún concepto es que al fusionar se pierda un expediente.
 */
class FusionarClientesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceType::create([
            'nombre' => 'Servicio de prueba',
            'slug' => 'servicio-de-prueba',
            'descripcion' => 'x',
            'modalidad' => 'permanente',
            'es_activo' => true,
        ]);
    }

    private function cliente(array $extra = []): Client
    {
        return Client::factory()->create($extra);
    }

    private function documento(Client $c, string $nombre): Document
    {
        return Document::create([
            'client_id' => $c->id,
            'nombre' => $nombre,
            'tipo' => 'otro',
            'disco' => 'local',
            'ruta' => 'x/'.$nombre,
        ]);
    }

    public function test_mueve_procesos_y_documentos_al_superviviente(): void
    {
        $vive = $this->cliente(['razon_social' => 'VISUAL PUBLICIDAD', 'nit' => '900437430']);
        $muere = $this->cliente(['razon_social' => 'VISUALED', 'nit' => null]);

        $proceso = Process::factory()->create(['client_id' => $muere->id]);
        $doc = $this->documento($muere, 'contrato.pdf');

        $this->artisan('clientes:fusionar', [
            'absorbido' => $muere->id,
            'superviviente' => $vive->id,
            '--ejecutar' => true,
        ])->assertSuccessful();

        $this->assertSame($vive->id, $proceso->fresh()->client_id);
        $this->assertSame($vive->id, $doc->fresh()->client_id);
        $this->assertSoftDeleted('clients', ['id' => $muere->id]);
    }

    /**
     * Lo más importante: la carpeta de Drive del absorbido no se pierde. Si se
     * perdiera, sus documentos dejarían de sincronizar y el expediente quedaría
     * congelado sin que nada avisara.
     */
    public function test_la_carpeta_de_drive_del_absorbido_se_conserva(): void
    {
        $vive = $this->cliente(['drive_folder_id' => 'carpeta-vive']);
        $muere = $this->cliente(['drive_folder_id' => 'carpeta-muere', 'drive_folder_name' => 'LEIDY / VISUALED']);

        $this->artisan('clientes:fusionar', [
            'absorbido' => $muere->id,
            'superviviente' => $vive->id,
            '--ejecutar' => true,
        ])->assertSuccessful();

        $carpetas = collect($vive->fresh()->todasLasCarpetasDrive())->pluck('id');

        $this->assertTrue($carpetas->contains('carpeta-vive'));
        $this->assertTrue($carpetas->contains('carpeta-muere'), 'la carpeta del absorbido debe seguir sincronizando');
    }

    /** Los datos que el superviviente no tenía se rescatan del absorbido. */
    public function test_rescata_los_datos_que_faltaban(): void
    {
        $vive = $this->cliente(['nit' => null, 'ciudad' => null, 'sector' => 'Publicidad']);
        $muere = $this->cliente(['nit' => '900437430', 'ciudad' => 'Ibagué', 'sector' => 'Otro']);

        $this->artisan('clientes:fusionar', [
            'absorbido' => $muere->id,
            'superviviente' => $vive->id,
            '--ejecutar' => true,
        ])->assertSuccessful();

        $vive->refresh();
        $this->assertSame('900437430', $vive->nit);
        $this->assertSame('Ibagué', $vive->ciudad);
        $this->assertSame('Publicidad', $vive->sector, 'lo que ya tenía no se pisa');
    }

    /** Quien estaba asignado a los dos no queda duplicado. */
    public function test_no_duplica_el_equipo_asignado(): void
    {
        $vive = $this->cliente();
        $muere = $this->cliente();
        $abogada = User::factory()->create();

        $vive->asignados()->attach($abogada->id, ['rol_asignacion' => 'lider']);
        $muere->asignados()->attach($abogada->id, ['rol_asignacion' => 'apoyo']);

        $this->artisan('clientes:fusionar', [
            'absorbido' => $muere->id,
            'superviviente' => $vive->id,
            '--ejecutar' => true,
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('client_user')
            ->where('client_id', $vive->id)->where('user_id', $abogada->id)->count());
    }

    /** Sin --ejecutar no se toca nada. */
    public function test_el_ensayo_no_mueve_nada(): void
    {
        $vive = $this->cliente();
        $muere = $this->cliente();
        $doc = $this->documento($muere, 'x.pdf');

        $this->artisan('clientes:fusionar', [
            'absorbido' => $muere->id,
            'superviviente' => $vive->id,
        ])->assertSuccessful();

        $this->assertSame($muere->id, $doc->fresh()->client_id);
        $this->assertNotSoftDeleted('clients', ['id' => $muere->id]);
    }

    /** Y la ficha queda marcada para regenerar: ahora hay más documentos. */
    public function test_marca_la_ficha_como_desactualizada(): void
    {
        $vive = $this->cliente(['resumen_documental' => 'ficha vieja', 'resumen_documental_at' => now()]);
        $muere = $this->cliente();

        $this->artisan('clientes:fusionar', [
            'absorbido' => $muere->id,
            'superviviente' => $vive->id,
            '--ejecutar' => true,
        ])->assertSuccessful();

        $this->assertNull($vive->fresh()->resumen_documental_at);
    }
}
