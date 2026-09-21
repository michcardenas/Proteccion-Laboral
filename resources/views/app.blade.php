<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0F172A">

        <title inertia>{{ config('app.name', 'Protección Laboral') }}</title>

        <link rel="icon" type="image/png" href="/images/logosinfondo.png">
        <link rel="apple-touch-icon" href="/images/logosinfondo.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        {{-- Enlace legal en TODAS las páginas, también la portada.
             Google exige que la política de privacidad esté enlazada desde la
             página principal para verificar los scopes de Gmail y Drive, y un
             titular de datos tiene derecho a encontrarla sin buscarla. Va en
             el Blade raíz y no en un componente de Vue para no obligar a
             reconstruir `public/build` —que aquí se versiona— por dos
             enlaces. --}}
        <footer style="padding:1.25rem;text-align:center;font-size:.78rem;color:#94a3b8;font-family:Figtree,ui-sans-serif,system-ui,sans-serif">
            <a href="{{ route('legal.show', 'politica-de-privacidad') }}" style="color:#64748b;text-decoration:none">Política de privacidad</a>
            <span style="margin:0 .5rem">·</span>
            <a href="{{ route('legal.show', 'terminos-y-condiciones') }}" style="color:#64748b;text-decoration:none">Términos y condiciones</a>
        </footer>
    </body>
</html>
