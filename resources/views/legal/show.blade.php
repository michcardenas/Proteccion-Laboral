{{--
    Página legal pública.

    Es Blade y no una página Inertia a propósito: el servidor no tiene node y
    `public/build` se versiona, así que una pantalla más en Vue obligaría a
    reconstruir y comitear los assets para un texto que casi nunca cambia. Aquí
    basta con guardar el contenido en la base.
--}}
@php
    $huecos = $pagina->huecosPendientes();
    $borrador = ! $pagina->publicado;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F172A">
    {{-- Un borrador no debe indexarse: no es un compromiso del despacho todavía. --}}
    @if ($borrador)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <title>{{ $pagina->titulo }} · {{ config('app.name', 'Protección Laboral') }}</title>
    @if ($pagina->resumen)
        <meta name="description" content="{{ $pagina->resumen }}">
    @endif
    <link rel="icon" type="image/png" href="/images/logosinfondo.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --tinta: #0f172a;
            --tinta-2: #334155;
            --suave: #64748b;
            --linea: #e2e8f0;
            --fondo: #f8fafc;
            --acento: #1d4ed8;
            --aviso-fondo: #fef3c7;
            --aviso-borde: #f59e0b;
            --aviso-tinta: #78350f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--fondo);
            color: var(--tinta-2);
            font-family: Figtree, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 17px;
            line-height: 1.7;
        }
        header {
            background: var(--tinta);
            color: #fff;
            padding: 2.4rem 1.25rem 2rem;
        }
        .caja { max-width: 46rem; margin: 0 auto; }
        header a { color: #cbd5e1; text-decoration: none; font-size: .82rem; letter-spacing: .08em; text-transform: uppercase; }
        header a:hover { color: #fff; }
        h1 { margin: .9rem 0 .4rem; font-size: 1.9rem; line-height: 1.2; color: #fff; font-weight: 700; }
        header p { margin: 0; color: #94a3b8; font-size: .98rem; }
        main { padding: 2.2rem 1.25rem 3.5rem; }
        .tarjeta {
            background: #fff;
            border: 1px solid var(--linea);
            border-radius: 12px;
            padding: 2rem clamp(1.1rem, 4vw, 2.4rem);
        }
        .aviso {
            background: var(--aviso-fondo);
            border: 1px solid var(--aviso-borde);
            border-left-width: 5px;
            border-radius: 8px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.8rem;
            color: var(--aviso-tinta);
            font-size: .95rem;
        }
        .aviso strong { display: block; margin-bottom: .25rem; }
        h2 { color: var(--tinta); font-size: 1.22rem; margin: 2.1rem 0 .7rem; font-weight: 600; }
        h2:first-of-type { margin-top: 0; }
        ul { padding-left: 1.2rem; }
        li { margin: .45rem 0; }
        a { color: var(--acento); }
        /* Los huecos sin rellenar saltan a la vista: es su única función. */
        mark.hueco {
            background: #fee2e2;
            color: #991b1b;
            border-radius: 4px;
            padding: .05em .35em;
            font-weight: 600;
            font-size: .93em;
        }
        footer {
            max-width: 46rem;
            margin: 1.6rem auto 0;
            padding: 0 1.25rem;
            color: var(--suave);
            font-size: .86rem;
            display: flex;
            flex-wrap: wrap;
            gap: .4rem 1.1rem;
            justify-content: space-between;
        }
        footer a { color: var(--suave); }
    </style>
</head>
<body>
<header>
    <div class="caja">
        <a href="{{ url('/') }}">← {{ config('app.name', 'Protección Laboral') }}</a>
        <h1>{{ $pagina->titulo }}</h1>
        @if ($pagina->resumen)
            <p>{{ $pagina->resumen }}</p>
        @endif
    </div>
</header>

<main>
    <div class="caja tarjeta">
        @if ($borrador)
            <div class="aviso">
                <strong>Borrador — sin aprobar</strong>
                Este texto todavía no ha sido revisado por la dirección del despacho y no constituye
                un compromiso.
                @if ($huecos > 0)
                    Quedan {{ $huecos }} {{ $huecos === 1 ? 'dato por completar' : 'datos por completar' }},
                    señalados en rojo.
                @endif
            </div>
        @endif

        {{-- El contenido lo escribe la dirección desde el panel; por eso se
             imprime sin escapar. La ruta de edición exige rol `director`. --}}
        {!! preg_replace(App\Models\LegalPage::MARCADOR, '<mark class="hueco">$0</mark>', $pagina->contenido) !!}
    </div>

    <footer>
        <span>
            @if ($pagina->publicado && $pagina->vigente_desde)
                Vigente desde {{ $pagina->vigente_desde->format('d/m/Y') }}.
            @endif
            Última actualización: {{ $pagina->updated_at->format('d/m/Y') }}.
        </span>
        <span>
            <a href="{{ route('legal.show', 'politica-de-privacidad') }}">Privacidad</a> ·
            <a href="{{ route('legal.show', 'terminos-y-condiciones') }}">Términos</a>
        </span>
    </footer>
</main>
</body>
</html>
