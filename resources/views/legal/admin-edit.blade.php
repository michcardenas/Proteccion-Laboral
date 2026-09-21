@php
    $huecos = $pagina->huecosPendientes();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Editar · {{ $pagina->titulo }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #334155; font-family: Figtree, ui-sans-serif, system-ui, sans-serif; }
        .caja { max-width: 60rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        h1 { color: #0f172a; font-size: 1.5rem; margin: .4rem 0 1.4rem; }
        a.volver { color: #64748b; text-decoration: none; font-size: .85rem; }
        form { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.6rem; }
        label { display: block; font-weight: 600; color: #0f172a; margin: 1.1rem 0 .35rem; font-size: .92rem; }
        input[type=text], input[type=date], textarea {
            width: 100%; padding: .65rem .8rem; border: 1px solid #cbd5e1; border-radius: 8px;
            font-family: inherit; font-size: .95rem; color: #0f172a; background: #fff;
        }
        textarea { min-height: 28rem; font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .86rem; line-height: 1.6; }
        .fila { display: flex; gap: 1.4rem; flex-wrap: wrap; align-items: flex-end; }
        .fila > div { flex: 1 1 14rem; }
        .check { display: flex; align-items: center; gap: .55rem; margin-top: 1.4rem; font-size: .95rem; }
        button { margin-top: 1.6rem; background: #1d4ed8; color: #fff; border: 0; border-radius: 8px; padding: .75rem 1.5rem; font-size: .95rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #1e40af; }
        .aviso { border-radius: 8px; padding: .9rem 1.1rem; margin-bottom: 1.2rem; font-size: .92rem; border-left: 5px solid; }
        .ok { background: #dcfce7; border-color: #16a34a; color: #14532d; }
        .mal { background: #fee2e2; border-color: #dc2626; color: #7f1d1d; }
        .pendiente { background: #fef3c7; border-color: #f59e0b; color: #78350f; }
        .ayuda { color: #64748b; font-size: .85rem; margin: .35rem 0 0; }
        code { background: #f1f5f9; padding: .1em .4em; border-radius: 4px; font-size: .88em; }
    </style>
</head>
<body>
<div class="caja">
    <a class="volver" href="{{ route('admin.legales.index') }}">← Páginas legales</a>
    <h1>{{ $pagina->titulo }}</h1>

    @if (session('success'))
        <div class="aviso ok">{{ session('success') }}</div>
    @endif
    @foreach ($errors->all() as $error)
        <div class="aviso mal">{{ $error }}</div>
    @endforeach
    @if ($huecos > 0)
        <div class="aviso pendiente">
            Quedan <strong>{{ $huecos }}</strong> marcador(es) <code>[[COMPLETAR: …]]</code> en el texto.
            Mientras existan, la página no se puede publicar.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.legales.update', $pagina) }}">
        @csrf
        @method('PUT')

        <label for="titulo">Título</label>
        <input type="text" id="titulo" name="titulo" value="{{ old('titulo', $pagina->titulo) }}" required>

        <label for="resumen">Resumen</label>
        <input type="text" id="resumen" name="resumen" value="{{ old('resumen', $pagina->resumen) }}">
        <p class="ayuda">Una línea. Sale bajo el título y como descripción para buscadores.</p>

        <label for="contenido">Contenido</label>
        <textarea id="contenido" name="contenido" required>{{ old('contenido', $pagina->contenido) }}</textarea>
        <p class="ayuda">
            Se escribe en HTML sencillo: <code>&lt;h2&gt;</code> para los títulos de apartado,
            <code>&lt;p&gt;</code> para los párrafos y <code>&lt;ul&gt;&lt;li&gt;</code> para las listas.
        </p>

        <div class="fila">
            <div>
                <label for="vigente_desde">Vigente desde</label>
                <input type="date" id="vigente_desde" name="vigente_desde"
                       value="{{ old('vigente_desde', $pagina->vigente_desde?->format('Y-m-d')) }}">
                <p class="ayuda">Si se publica sin fecha, se pone la de hoy.</p>
            </div>
            <div>
                <label class="check">
                    <input type="checkbox" name="publicado" value="1" @checked(old('publicado', $pagina->publicado))>
                    Publicada (visible y sin cartel de borrador)
                </label>
            </div>
        </div>

        <button type="submit">Guardar</button>
    </form>

    <p class="ayuda" style="margin-top:1.2rem">
        Dirección pública: <a href="{{ route('legal.show', $pagina->slug) }}" target="_blank" rel="noopener">{{ route('legal.show', $pagina->slug) }}</a>
        @if ($pagina->actualizadoPor)
            · último cambio por {{ $pagina->actualizadoPor->name }}
        @endif
    </p>
</div>
</body>
</html>
