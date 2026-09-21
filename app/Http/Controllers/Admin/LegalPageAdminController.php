<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Edición de las páginas legales. Solo dirección.
 *
 * Es una pantalla Blade y no Inertia por lo mismo que la pública: sin node en
 * el servidor, una pantalla en Vue obliga a reconstruir y comitear
 * `public/build` para un formulario que se abre tres veces al año.
 */
class LegalPageAdminController extends Controller
{
    public function index()
    {
        return view('legal.admin-index', [
            'paginas' => LegalPage::orderBy('slug')->get(),
        ]);
    }

    public function edit(LegalPage $legal)
    {
        return view('legal.admin-edit', ['pagina' => $legal]);
    }

    public function update(Request $request, LegalPage $legal): RedirectResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'resumen' => ['nullable', 'string', 'max:255'],
            'contenido' => ['required', 'string'],
            'vigente_desde' => ['nullable', 'date'],
        ]);

        $datos['publicado'] = $request->boolean('publicado');
        $datos['actualizado_por'] = $request->user()->id;

        $legal->fill($datos);

        // Publicar con huecos sin rellenar sería peor que no publicar: el
        // documento parecería un compromiso del despacho y no diría nada. Y es
        // justo la URL que Google va a leer para verificar la app.
        if ($legal->publicado && ! $legal->puedePublicarse()) {
            throw ValidationException::withMessages([
                'contenido' => 'No se puede publicar con datos sin completar: quedan '
                    .$legal->huecosPendientes().' marcador(es) [[COMPLETAR: …]] en el texto.',
            ]);
        }

        // Una página que se publica sin fecha de vigencia la toma de hoy: un
        // texto legal sin fecha no sirve para acreditar desde cuándo rige.
        if ($legal->publicado && ! $legal->vigente_desde) {
            $legal->vigente_desde = now()->toDateString();
        }

        $legal->save();

        return redirect()
            ->route('admin.legales.edit', $legal)
            ->with('success', $legal->publicado
                ? 'Página publicada. Ya es visible en '.route('legal.show', $legal->slug)
                : 'Borrador guardado. Sigue sin publicar.');
    }
}
