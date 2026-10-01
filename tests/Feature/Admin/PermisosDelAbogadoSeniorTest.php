<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que puede y no puede un abogado senior.
 *
 * El rol se describe como «ve todos los clientes, gestiona procesos y
 * contratos, y asigna». Pero asignar abogadas a un cliente está protegido por
 * `clients.update`, no por `processes.assign`, y ese permiso se habia quedado
 * fuera: el rol no podia asignar en el unico sitio donde se decide quien lleva
 * a quien.
 *
 * Lo que sigue sin poder, y es a proposito: crear y borrar clientes.
 */
class PermisosDelAbogadoSeniorTest extends TestCase
{
    use RefreshDatabase;

    private function senior(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return tap(User::factory()->create())->assignRole('abogado_senior');
    }

    public function test_puede_asignar_el_equipo_de_un_cliente(): void
    {
        $senior = $this->senior();
        $otra = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($senior)
            ->post(route('admin.clients.assignments.store', $client), [
                'user_id' => $otra->id,
                'rol_asignacion' => 'lider',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_user', [
            'client_id' => $client->id,
            'user_id' => $otra->id,
        ]);
    }

    /** Y editar la ficha, que va con el mismo permiso. */
    public function test_puede_editar_la_ficha_del_cliente(): void
    {
        $this->actingAs($this->senior())
            ->get(route('admin.clients.edit', Client::factory()->create()))
            ->assertOk();
    }

    /**
     * Y puede ser abogada líder. Las listas de personal de los formularios
     * filtraban por rol y `abogado_senior` no estaba en ninguna: Leidy no
     * podía ponerse como líder de un proceso, y sin líder el cliente no entra
     * al portal. Peor aún, un proceso donde ya era líder perdía el líder al
     * editarlo y guardar, porque el select no tenía su opción.
     */
    public function test_aparece_como_opcion_de_abogado_lider_y_de_equipo(): void
    {
        $senior = $this->senior();
        $client = Client::factory()->create();

        $this->actingAs($senior)
            ->get(route('admin.processes.create'))
            ->assertInertia(fn ($page) => $page->where('staff', fn ($staff) => collect($staff)->contains('id', $senior->id)));

        $this->actingAs($senior)
            ->get(route('admin.clients.show', $client))
            ->assertInertia(fn ($page) => $page->where('potentialAssignees', fn ($u) => collect($u)->contains('id', $senior->id)));
    }

    /** Pero crear clientes sigue siendo de coordinación y dirección. */
    public function test_no_puede_crear_clientes(): void
    {
        $this->actingAs($this->senior())
            ->get(route('admin.clients.create'))
            ->assertForbidden();
    }
}
