<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Páginas legales</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; background: #f1f5f9; color: #334155; font-family: Figtree, ui-sans-serif, system-ui, sans-serif; }
        .caja { max-width: 52rem; margin: 0 auto; padding: 2.4rem 1.25rem; }
        h1 { color: #0f172a; font-size: 1.5rem; margin: .3rem 0 .4rem; }
        p.intro { color: #64748b; margin: 0 0 1.8rem; }
        a.volver { color: #64748b; text-decoration: none; font-size: .85rem; }
        .item { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.2rem 1.4rem; margin-bottom: 1rem; display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .item h2 { margin: 0 0 .2rem; font-size: 1.05rem; color: #0f172a; }
        .item p { margin: 0; font-size: .88rem; color: #64748b; }
        .etiqueta { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: .25rem .6rem; border-radius: 999px; }
        .pub { background: #dcfce7; color: #14532d; }
        .bor { background: #fef3c7; color: #78350f; }
        .editar { background: #1d4ed8; color: #fff; text-decoration: none; padding: .55rem 1.1rem; border-radius: 8px; font-size: .9rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="caja">
    <a class="volver" href="{{ url('/dashboard') }}">← Volver</a>
    <h1>Páginas legales</h1>
    <p class="intro">El texto lo escribe la dirección. Guardar aquí lo publica sin desplegar nada.</p>

    @foreach ($paginas as $pagina)
        <div class="item">
            <div>
                <h2>{{ $pagina->titulo }}</h2>
                <p>
                    /{{ $pagina->slug }} ·
                    @if ($pagina->huecosPendientes() > 0)
                        {{ $pagina->huecosPendientes() }} dato(s) por completar
                    @else
                        texto completo
                    @endif
                    · actualizada {{ $pagina->updated_at->format('d/m/Y') }}
                </p>
            </div>
            <div style="display:flex;gap:.8rem;align-items:center">
                <span class="etiqueta {{ $pagina->publicado ? 'pub' : 'bor' }}">
                    {{ $pagina->publicado ? 'Publicada' : 'Borrador' }}
                </span>
                <a class="editar" href="{{ route('admin.legales.edit', $pagina) }}">Editar</a>
            </div>
        </div>
    @endforeach
</div>
</body>
</html>
