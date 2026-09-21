{{--
    Portada pública.

    Antes era la pantalla de bienvenida que trae Laravel de fábrica, con los
    enlaces a la documentación del framework: lo primero que veía un cliente del
    despacho al abrir la dirección.

    La dirección estética es la de un expediente: papel pautado, regla de
    margen, sello y una tipografía con autoridad. Nada de promesas comerciales
    —el despacho dirá lo suyo cuando quiera—; esto solo explica qué es la
    plataforma y reparte las dos puertas de entrada.

    Es Blade y no Inertia por lo mismo que las páginas legales: el servidor no
    tiene node y `public/build` se versiona, así que una pantalla en Vue
    obligaría a reconstruir y comitear todos los assets.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1220">
    <title>{{ config('app.name', 'Protección Laboral') }} · Gestión de procesos laborales</title>
    <meta name="description" content="Plataforma de gestión de procesos laborales: expedientes, documentos y actuaciones de cada caso, con acceso nominal para el equipo del despacho y para sus clientes.">
    <link rel="icon" type="image/png" href="/images/logosinfondo.png">
    <link rel="apple-touch-icon" href="/images/logosinfondo.png">

    {{-- Bunny Fonts, como el resto de la aplicación: mismo servicio, sin
         cookies de terceros — que en un despacho que publica política de datos
         no es un detalle menor. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,600,700|archivo:400,500,600&display=swap" rel="stylesheet">

    <style>
        :root {
            --noche:      #0b1220;
            --noche-2:    #111c2e;
            --pergamino:  #f2ede3;
            --pergamino-2:#cfc7b6;
            --niebla:     #8a93a5;
            --sello:      #c08a2e;
            --sello-alto: #e0a93f;
            --linea:      rgba(242, 237, 227, .12);
            --serif:      Fraunces, "Iowan Old Style", Georgia, serif;
            --sans:       Archivo, ui-sans-serif, system-ui, sans-serif;
        }

        * { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--noche);
            color: var(--pergamino);
            font-family: var(--sans);
            font-size: 16px;
            line-height: 1.65;
            position: relative;
            overflow-x: hidden;
        }

        /* Papel pautado: las líneas de un cuaderno de apuntes, casi invisibles.
           Dan textura sin competir con el texto. */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                repeating-linear-gradient(
                    to bottom,
                    transparent 0 31px,
                    rgba(242, 237, 227, .035) 31px 32px
                ),
                radial-gradient(120% 80% at 82% -10%, rgba(192, 138, 46, .14), transparent 60%),
                radial-gradient(90% 60% at 0% 100%, rgba(17, 28, 46, .9), transparent 70%);
        }

        /* Grano: una capa de ruido finísima para que el fondo no sea plástico. */
        body::after {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: .16;
            mix-blend-mode: overlay;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Cfilter id='r'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.82' numOctaves='3'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23r)'/%3E%3C/svg%3E");
        }

        .hoja {
            position: relative;
            z-index: 1;
            max-width: 68rem;
            margin: 0 auto;
            padding: 2rem clamp(1.15rem, 5vw, 3.5rem) 3rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Cabecera ───────────────────────────────────────────────── */
        .marca {
            display: flex;
            align-items: center;
            gap: .85rem;
            padding-bottom: 1.6rem;
            border-bottom: 1px solid var(--linea);
        }
        /* El logotipo es trazo NEGRO sobre transparente y trae el nombre
           debajo: sobre fondo oscuro no se veía, y repetía el texto que ya va
           al lado. Se recorta a la marca hexagonal y se invierte a pergamino. */
        .marca__sello { width: 48px; height: 46px; overflow: hidden; position: relative; flex: none; }
        .marca__sello img {
            position: absolute; width: 100px; left: -28px; top: -1px;
            filter: brightness(0) invert(1);
            opacity: .9;
        }
        .marca__texto { font-family: var(--serif); font-weight: 600; font-size: 1.05rem; letter-spacing: .01em; }
        .marca__nota {
            margin-left: auto;
            font-size: .72rem;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--niebla);
        }

        /* ── Cuerpo ─────────────────────────────────────────────────── */
        .cuerpo {
            flex: 1;
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: clamp(2rem, 6vw, 5rem);
            align-items: center;
            padding: clamp(2.5rem, 8vw, 5.5rem) 0;
        }

        .epigrafe {
            font-size: .74rem;
            letter-spacing: .26em;
            text-transform: uppercase;
            color: var(--sello-alto);
            display: flex;
            align-items: center;
            gap: .8rem;
        }
        .epigrafe::before {
            content: "";
            width: 2.2rem;
            height: 1px;
            background: var(--sello);
        }

        h1 {
            font-family: var(--serif);
            font-weight: 600;
            font-size: clamp(2.6rem, 7vw, 4.4rem);
            line-height: 1.02;
            letter-spacing: -.02em;
            margin: 1.2rem 0 0;
        }
        /* La segunda línea del título, en cursiva: el guiño editorial que
           separa el nombre del oficio. */
        h1 em {
            display: block;
            font-style: italic;
            font-weight: 400;
            color: var(--pergamino-2);
            font-size: .46em;
            letter-spacing: 0;
            margin-top: .55rem;
            max-width: 15ch;
            text-wrap: balance;
        }

        /* Regla de margen, como la línea roja de un cuaderno jurídico. */
        .entrada {
            margin: 2rem 0 0;
            padding-left: 1.4rem;
            border-left: 2px solid var(--sello);
            max-width: 34rem;
            color: var(--pergamino-2);
            font-size: 1.04rem;
        }

        .apunte {
            margin-top: 2.2rem;
            font-size: .85rem;
            color: var(--niebla);
            display: flex;
            gap: 1.6rem;
            flex-wrap: wrap;
        }
        .apunte span { display: flex; align-items: center; gap: .5rem; }
        .apunte span::before {
            content: "";
            width: 5px; height: 5px;
            background: var(--sello);
            transform: rotate(45deg);
        }

        /* ── Las dos puertas ────────────────────────────────────────── */
        .puertas { display: grid; gap: 1.1rem; }

        .puerta {
            position: relative;
            display: block;
            text-decoration: none;
            color: inherit;
            background: linear-gradient(160deg, rgba(242,237,227,.055), rgba(242,237,227,.02));
            border: 1px solid var(--linea);
            /* Esquina recortada: la pestaña de una carpeta. */
            clip-path: polygon(0 0, calc(100% - 18px) 0, 100% 18px, 100% 100%, 0 100%);
            padding: 1.5rem 1.6rem 1.35rem;
            transition: transform .45s cubic-bezier(.2,.7,.3,1), background .45s, border-color .45s;
        }
        .puerta:hover {
            transform: translateY(-4px);
            background: linear-gradient(160deg, rgba(242,237,227,.09), rgba(242,237,227,.03));
            border-color: rgba(224, 169, 63, .5);
        }
        .puerta__num {
            font-family: var(--serif);
            font-size: .78rem;
            color: var(--sello-alto);
            letter-spacing: .1em;
        }
        .puerta h2 {
            font-family: var(--serif);
            font-weight: 600;
            font-size: 1.32rem;
            margin: .35rem 0 .35rem;
            letter-spacing: -.01em;
        }
        .puerta p { margin: 0; color: var(--niebla); font-size: .9rem; line-height: 1.55; }
        .puerta__ir {
            margin-top: 1.1rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .78rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--pergamino);
        }
        .puerta__ir svg { transition: transform .45s cubic-bezier(.2,.7,.3,1); }
        .puerta:hover .puerta__ir svg { transform: translateX(6px); }

        /* ── Pie ────────────────────────────────────────────────────── */
        footer {
            border-top: 1px solid var(--linea);
            padding-top: 1.4rem;
            display: flex;
            flex-wrap: wrap;
            gap: .6rem 1.4rem;
            justify-content: space-between;
            align-items: center;
            font-size: .82rem;
            color: var(--niebla);
        }
        footer a {
            color: var(--pergamino-2);
            text-decoration: none;
            border-bottom: 1px solid transparent;
            padding-bottom: 1px;
            transition: border-color .3s, color .3s;
        }
        footer a:hover { color: var(--sello-alto); border-bottom-color: var(--sello); }
        footer nav { display: flex; gap: 1.2rem; flex-wrap: wrap; }

        /* ── Entrada en escena ──────────────────────────────────────── */
        @keyframes sube {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: none; }
        }
        .anima { opacity: 0; animation: sube .85s cubic-bezier(.2,.7,.3,1) forwards; }
        .anima[style*="--d"] { animation-delay: var(--d); }

        @media (max-width: 900px) {
            .cuerpo { grid-template-columns: 1fr; gap: 2.6rem; padding: 2.5rem 0 3rem; align-items: start; }
            .marca__nota { display: none; }
            footer { flex-direction: column; align-items: flex-start; }
        }

        /* Quien pide menos movimiento, lo recibe: todo aparece, nada se desliza. */
        @media (prefers-reduced-motion: reduce) {
            .anima { animation: none; opacity: 1; }
            .puerta, .puerta__ir svg { transition: none; }
            .puerta:hover { transform: none; }
        }
    </style>
</head>
<body>
<div class="hoja">

    <header class="marca anima" style="--d:.05s">
        <span class="marca__sello"><img src="/images/logosinfondo.png" alt=""></span>
        <span class="marca__texto">{{ config('app.name', 'Protección Laboral') }}</span>
        <span class="marca__nota">Expedientes</span>
    </header>

    <main class="cuerpo">
        <div>
            <p class="epigrafe anima" style="--d:.15s">Plataforma de gestión</p>

            <h1 class="anima" style="--d:.25s">
                {{ config('app.name', 'Protección Laboral') }}
                <em>Procesos laborales, de principio a fin</em>
            </h1>

            <p class="entrada anima" style="--d:.38s">
                Cada caso con su expediente: actuaciones, documentos, comunicaciones y plazos
                en un mismo sitio, para que nada dependa de acordarse.
            </p>

            <div class="apunte anima" style="--d:.5s">
                <span>Acceso nominal por rol</span>
                <span>Historial de cada actuación</span>
                <span>Documentos del expediente</span>
            </div>
        </div>

        <div class="puertas">
            <a class="puerta anima" style="--d:.6s" href="{{ route('login') }}">
                <span class="puerta__num">01 — Despacho</span>
                <h2>Equipo</h2>
                <p>Dirección, coordinación y abogados: gestión de procesos, clientes y documentos.</p>
                <span class="puerta__ir">
                    Ingresar
                    <svg width="18" height="10" viewBox="0 0 18 10" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path d="M0 5h16M12 1l4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </a>

            <a class="puerta anima" style="--d:.72s" href="{{ route('portal.login') }}">
                <span class="puerta__num">02 — Clientes</span>
                <h2>Consulte su proceso</h2>
                <p>Si es cliente del despacho, consulte el estado y los documentos de sus casos.</p>
                <span class="puerta__ir">
                    Entrar al portal
                    <svg width="18" height="10" viewBox="0 0 18 10" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path d="M0 5h16M12 1l4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </a>
        </div>
    </main>

    <footer class="anima" style="--d:.85s">
        <span>© {{ date('Y') }} {{ config('app.name', 'Protección Laboral') }}</span>
        <nav>
            <a href="{{ route('legal.show', 'politica-de-privacidad') }}">Política de privacidad</a>
            <a href="{{ route('legal.show', 'terminos-y-condiciones') }}">Términos y condiciones</a>
        </nav>
    </footer>

</div>
</body>
</html>
