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

    /** Pero crear clientes sigue siendo de coordinación y dirección. */
    public function test_no_puede_crear_clientes(): void
    {
        $this->actingAs($this->senior())
            ->get(route('admin.clients.create'))
            ->assertForbidden();
    }
}
