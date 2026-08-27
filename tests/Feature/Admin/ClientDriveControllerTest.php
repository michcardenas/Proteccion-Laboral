<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncClientDrive;
use App\Models\Client;
use App\Models\User;
use App\Services\DriveKnowledgeSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El botón «traer documentos de Drive».
 *
 * La regla que sostiene todo: MIRA primero y solo encola si hay algo. Consultar
 * la carpeta de Drive es una llamada barata a Google y no gasta IA; rehacer la
 * ficha sí — se regenera ENTERA cada vez, y en un cliente con 178 documentos
 * son ~$0,28 por pasada. Pulsar el botón sin novedades no puede costar nada.
 */
class ClientDriveControllerTest extends TestCase
{
    use RefreshDatabase;

    private function abogado(): User
    {
        $permiso = Permission::firstOrCreate(['name' => 'documents.upload', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'abogado_interno', 'guard_name' => 'web']);
        $rol->givePermissionTo($permiso);

        return tap(User::factory()->create())->assignRole($rol);
    }

    private function cliente(): Client
    {
        return Client::factory()->create(['drive_folder_id' => 'carpeta-abc']);
    }

    /** @param array<string,int> $stats */
    private function sync(array $stats): void
    {
        $mock = Mockery::mock(DriveKnowledgeSync::class);
        $mock->shouldReceive('syncClient')->andReturn(array_merge(
            ['nuevos' => 0, 'actualizados' => 0, 'omitidos' => 0, 'eliminados' => 0, 'errores' => 0],
            $stats
        ));
        $this->app->instance(DriveKnowledgeSync::class, $mock);
    }

    public function test_sin_documentos_nuevos_no_encola_nada_y_lo_dice(): void
    {
        Queue::fake();
        $this->sync(['nuevos' => 0, 'actualizados' => 0, 'omitidos' => 40]);

        $this->actingAs($this->abogado())
            ->post(route('admin.clients.drive.sync', $this->cliente()))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'No hay documentos nuevos'));

        Queue::assertNothingPushed();
    }

    public function test_con_documentos_nuevos_encola_el_trabajo(): void
    {
        Queue::fake();
        $this->sync(['nuevos' => 3]);

        $this->actingAs($this->abogado())
            ->post(route('admin.clients.drive.sync', $this->cliente()))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 documentos nuevos'));

        Queue::assertPushed(SyncClientDrive::class);
    }

    /** Un documento que cambió en Drive también cuenta como trabajo pendiente. */
    public function test_los_actualizados_tambien_disparan_la_sincronizacion(): void
    {
        Queue::fake();
        $this->sync(['nuevos' => 0, 'actualizados' => 2]);

        $this->actingAs($this->abogado())
            ->post(route('admin.clients.drive.sync', $this->cliente()))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 actualizados'));

        Queue::assertPushed(SyncClientDrive::class);
    }

    public function test_un_cliente_sin_carpeta_lo_dice_sin_llamar_a_drive(): void
    {
        Queue::fake();
        $mock = Mockery::mock(DriveKnowledgeSync::class);
        $mock->shouldNotReceive('syncClient');
        $this->app->instance(DriveKnowledgeSync::class, $mock);

        $cliente = Client::factory()->create(['drive_folder_id' => null]);

        $this->actingAs($this->abogado())
            ->post(route('admin.clients.drive.sync', $cliente))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no tiene carpeta de Drive'));

        Queue::assertNothingPushed();
    }

    /** Si Drive falla, el usuario se entera y no se encola trabajo a ciegas. */
    public function test_si_drive_falla_lo_reporta_y_no_encola(): void
    {
        Queue::fake();
        $mock = Mockery::mock(DriveKnowledgeSync::class);
        $mock->shouldReceive('syncClient')->andThrow(new RuntimeException('token sin permiso de Drive'));
        $this->app->instance(DriveKnowledgeSync::class, $mock);

        $this->actingAs($this->abogado())
            ->post(route('admin.clients.drive.sync', $this->cliente()))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'token sin permiso'));

        Queue::assertNothingPushed();
    }

    /** Traer documentos es subir documentos: sin ese permiso, no. */
    public function test_sin_permiso_no_se_puede(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.drive.sync', $this->cliente()))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /**
     * Pulsar el botón tres veces «por si acaso» no puede costar tres
     * regeneraciones de ficha.
     */
    public function test_el_job_es_unico_por_cliente(): void
    {
        $job = new SyncClientDrive(7);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('drive-sync:7', $job->uniqueId());
    }
}
