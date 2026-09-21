<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La plataforma no tenía ninguna página legal, y maneja lo más delicado del
 * despacho: trabajadores identificados, pleitos e incapacidades médicas.
 * Además pide a Google scopes restringidos (Gmail y Drive), y esa verificación
 * exige una política de privacidad pública.
 *
 * Lo que se fija aquí es lo que no puede romperse: que las páginas se lean SIN
 * sesión, y que no se publique un texto con huecos sin rellenar.
 */
class PaginasLegalesTest extends TestCase
{
    use RefreshDatabase;

    private function director(): User
    {
        Role::findOrCreate('director', 'web');

        return tap(User::factory()->create(['is_active' => true]))
            ->assignRole('director');
    }

    public function test_la_politica_se_lee_sin_iniciar_sesion(): void
    {
        $this->get('/politica-de-privacidad')
            ->assertOk()
            ->assertSee('Política de tratamiento de datos personales')
            ->assertSee('Ley 1581 de 2012');
    }

    public function test_los_terminos_tambien_estan_en_abierto(): void
    {
        $this->get('/terminos-y-condiciones')->assertOk();
    }

    public function test_una_pagina_legal_inventada_no_existe(): void
    {
        $this->get('/politica-inventada')->assertNotFound();
    }

    public function test_un_borrador_avisa_de_que_lo_es_y_no_se_indexa(): void
    {
        $this->get('/politica-de-privacidad')
            ->assertOk()
            ->assertSee('Borrador')
            ->assertSee('noindex, nofollow', false);
    }

    public function test_el_enlace_legal_sale_en_la_portada(): void
    {
        // Es lo que mira el verificador de Google antes de aprobar los scopes.
        $this->get('/')
            ->assertOk()
            ->assertSee('/politica-de-privacidad', false);
    }

    public function test_solo_la_direccion_edita_las_paginas_legales(): void
    {
        $pagina = LegalPage::where('slug', 'politica-de-privacidad')->first();

        $this->get(route('admin.legales.edit', $pagina))->assertRedirect();

        Role::findOrCreate('abogado_interno', 'web');
        $abogado = tap(User::factory()->create(['is_active' => true]))->assignRole('abogado_interno');

        $this->actingAs($abogado)
            ->get(route('admin.legales.edit', $pagina))
            ->assertForbidden();

        $this->actingAs($this->director())
            ->get(route('admin.legales.edit', $pagina))
            ->assertOk();
    }

    public function test_no_se_publica_con_datos_sin_completar(): void
    {
        $pagina = LegalPage::where('slug', 'politica-de-privacidad')->first();

        $this->assertGreaterThan(0, $pagina->huecosPendientes());

        $this->actingAs($this->director())
            ->put(route('admin.legales.update', $pagina), [
                'titulo' => $pagina->titulo,
                'contenido' => $pagina->contenido,
                'publicado' => '1',
            ])
            ->assertSessionHasErrors('contenido');

        $this->assertFalse($pagina->fresh()->publicado);
    }

    public function test_el_texto_completo_si_se_publica_y_toma_fecha_de_hoy(): void
    {
        $pagina = LegalPage::where('slug', 'politica-de-privacidad')->first();
        $director = $this->director();

        $this->actingAs($director)
            ->put(route('admin.legales.update', $pagina), [
                'titulo' => 'Política de tratamiento de datos personales',
                'contenido' => '<h2>1. Responsable</h2><p>Texto ya revisado por el despacho.</p>',
                'publicado' => '1',
            ])
            ->assertSessionHasNoErrors();

        $pagina->refresh();

        $this->assertTrue($pagina->publicado);
        $this->assertSame(now()->toDateString(), $pagina->vigente_desde->toDateString());
        $this->assertSame($director->id, $pagina->actualizado_por);

        // Publicada: ni cartel de borrador ni noindex.
        $this->get('/politica-de-privacidad')
            ->assertOk()
            ->assertDontSee('Borrador — sin aprobar')
            ->assertDontSee('noindex', false);
    }

    public function test_el_borrador_deja_ver_que_datos_faltan(): void
    {
        $pagina = LegalPage::where('slug', 'politica-de-privacidad')->first();

        // Los marcadores se pintan resaltados para que nadie los pase por alto.
        $this->get('/politica-de-privacidad')
            ->assertOk()
            ->assertSee('mark class="hueco"', false);
    }
}
