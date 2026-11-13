{{-- resources/views/app.blade.php — unique vue Blade : coquille HTML dans laquelle Inertia monte React. --}}
<!DOCTYPE html>
<html lang="fr" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0b0b12">
        {{-- Lu par Laravel Echo pour autoriser le canal privé de l'hôte (POST /broadcasting/auth). --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Jukebox') }}</title>

        {{-- Rafraîchissement à chaud de React en développement. --}}
        @viteReactRefresh
        {{-- Le point d'entrée + la page courante uniquement (découpage du code par page). --}}
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="h-full bg-ink-950 font-sans text-slate-100 antialiased">
        @inertia
    </body>
</html>
