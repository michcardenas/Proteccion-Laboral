<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Process;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Corregir el servicio de un proceso ya creado.
 *
 * Estaba congelado a propósito —el servicio define las etapas que se clonan al
 * crear— y eso valía mientras todos los procesos nacieran a mano en la app. Al
 * importar los asuntos de Drive dejó de valer: los 53 entran con un servicio
 * genérico, el único que no miente para todos, y hace falta corregirlos uno a
 * uno sin borrarlos y volverlos a crear.
 *
 * Cambiarlo NO reescribe las etapas: son filas propias del proceso desde que se
 * clonaron. La pantalla lo advierte y esta prueba lo fija.
 */
class CambiarServicioDelProcesoTest extends TestCase
{
    use RefreshDatabase;

    private function director(): User
    {
        $rol = Role::firstOrCreate(['name' => 'director', 'guard_name' => 'web']);
        foreach (['processes.view', 'processes.update'] as $p) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }

        return tap(User::factory()->create())->assignRole($rol);
    }

    private function servicio(string $nombre): ServiceType
    {
        return ServiceType::create([
            'nombre' => $nombre,
            'slug' => Str::slug($nombre),
            'modalidad' => 'judicial',
            'es_activo' => true,
        ]);
    }

    private function proceso(ServiceType $servicio): Process
    {
        return Process::create([
            'client_id' => Client::factory()->create()->id,
            'service_type_id' => $servicio->id,
            'codigo' => 'PL-ELIAS-16COMPRAVENTA',
            'titulo' => '16 COMPRAVENTA GARCES',
            'drive_folder' => '16 COMPRAVENTA GARCES',
            'estado' => 'en_curso',
            'fecha_apertura' => '2024-12-03',
        ]);
    }

    /** @return array<string,mixed> */
    private function datos(Process $p, int $servicioId): array
    {
        return [
            'service_type_id' => $servicioId,
            'codigo' => $p->codigo,
            'titulo' => $p->titulo,
            'estado' => $p->estado,
            'fecha_apertura' => $p->fecha_apertura->format('Y-m-d'),
        ];
    }

    public function test_se_puede_corregir_el_servicio_de_un_proceso_importado(): void
    {
        $generico = $this->servicio('Asesoría Laboral Permanente');
        $real = $this->servicio('Servicio por Evento');
        $proceso = $this->proceso($generico);

        $this->actingAs($this->director())
            ->put(route('admin.processes.update', $proceso), $this->datos($proceso, $real->id))
            ->assertRedirect();

        $this->assertSame($real->id, $proceso->fresh()->service_type_id);
    }

    /** No vale mandar cualquier número: tiene que ser un servicio que exista. */
    public function test_un_servicio_inexistente_se_rechaza(): void
    {
        $proceso = $this->proceso($this->servicio('Asesoría Laboral Permanente'));

        $this->actingAs($this->director())
            ->put(route('admin.processes.update', $proceso), $this->datos($proceso, 99999))
            ->assertSessionHasErrors('service_type_id');
    }

    /** Y sin permiso de edición, no. */
    public function test_sin_permiso_no_se_puede_cambiar(): void
    {
        $generico = $this->servicio('Asesoría Laboral Permanente');
        $real = $this->servicio('Servicio por Evento');
        $proceso = $this->proceso($generico);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.processes.update', $proceso), $this->datos($proceso, $real->id))
            ->assertForbidden();

        $this->assertSame($generico->id, $proceso->fresh()->service_type_id);
    }
}
