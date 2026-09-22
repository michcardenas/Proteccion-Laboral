<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cambiar si el cliente ve un documento despues de subirlo.
 *
 * La visibilidad solo se elegia al subir: para compartir un documento ya
 * subido habia que volver a subirlo. Lo decide quien tiene
 * `documents.share_with_client`, un permiso que existia y nada comprobaba.
 */
class VisibilidadDeDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private function con(string $rol): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return tap(User::factory()->create())->assignRole($rol);
    }

    private function documentoDelCliente(bool $visible = false): Document
    {
        return Document::create([
            'client_id' => Client::factory()->create()->id,
            'nombre' => 'Contrato.pdf',
            'ruta' => 'docs/contrato.pdf',
            'disco' => 'local',
            'tipo' => 'contrato',
            'generado_por_ia' => false,
            'visible_cliente' => $visible,
        ]);
    }

    public function test_la_abogada_comparte_un_documento_ya_subido(): void
    {
        $doc = $this->documentoDelCliente(false);

        $this->actingAs($this->con('abogado_interno'))
            ->patch(route('admin.documents.visibility', $doc), ['visible_cliente' => true])
            ->assertRedirect();

        $this->assertTrue($doc->fresh()->visible_cliente);
    }

    public function test_y_puede_dejar_de_compartirlo(): void
    {
        $doc = $this->documentoDelCliente(true);

        $this->actingAs($this->con('coordinador'))
            ->patch(route('admin.documents.visibility', $doc), ['visible_cliente' => false])
            ->assertRedirect();

        $this->assertFalse($doc->fresh()->visible_cliente);
    }

    /** Sin `documents.share_with_client` no se decide que ve el cliente. */
    public function test_el_contador_no_cambia_la_visibilidad(): void
    {
        $doc = $this->documentoDelCliente(false);

        $this->actingAs($this->con('contador'))
            ->patch(route('admin.documents.visibility', $doc), ['visible_cliente' => true])
            ->assertForbidden();

        $this->assertFalse($doc->fresh()->visible_cliente);
    }
}
