<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-shell" data-theme="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Ekko') }}</title>

        {{-- Theme is applied before the first paint, from localStorage (ekko.theme): dark, light or system. --}}
        <script>
            (function () {
                var pref = null;
                try { pref = localStorage.getItem('ekko.theme'); } catch (e) {}
                var light = pref === 'light' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
                document.documentElement.dataset.theme = light ? 'light' : 'dark';
            })();
        </script>

        <link rel="icon" type="image/svg+xml" href="/images/maksimer-m-square.svg">

        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="h-full font-sans antialiased">
        @inertia
    </body>
</html>
