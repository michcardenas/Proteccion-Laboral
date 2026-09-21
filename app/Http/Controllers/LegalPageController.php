<?php

namespace App\Http\Controllers;

use App\Models\LegalPage;

/**
 * Las páginas legales, en abierto.
 *
 * Van sin sesión a propósito: un titular de datos tiene que poder leer la
 * política sin ser usuario, y el verificador de Google tiene que poder abrir
 * la URL para aprobar los scopes de Gmail y Drive.
 */
class LegalPageController extends Controller
{
    public function show(string $slug)
    {
        $pagina = LegalPage::where('slug', $slug)->firstOrFail();

        return response()->view('legal.show', ['pagina' => $pagina]);
    }
}
