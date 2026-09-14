<!DOCTYPE html>
{{--
    The kitchen screen has its own shell: no sidebar, no profile menu, no way
    to wander off. It is mounted on a wall, wiped with a damp cloth, and read
    from two metres away, so it gets the whole viewport and nothing else.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        {{-- Only this screen opens a websocket, so only this screen loads one. --}}
        @vite('resources/js/echo.js')
    </head>
    <body class="kds-body">
        {{ $slot }}

        @fluxScripts
    </body>
</html>
