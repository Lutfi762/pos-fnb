<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center bg-base p-6 text-content">
        <main class="motion-rise w-full max-w-md text-center">
            <x-app-logo-icon class="mx-auto size-12 text-brand" />

            <h1 class="mt-6 font-display text-3xl font-semibold">{{ config('app.name') }}</h1>

            <p class="mt-2 text-content-muted">{{ __('welcome.tagline') }}</p>

            <div class="mt-8 flex items-center justify-center gap-3">
                @auth
                    <flux:button variant="primary" :href="route('dashboard')" wire:navigate>
                        {{ __('welcome.dashboard') }}
                    </flux:button>
                @else
                    <flux:button variant="primary" :href="route('login')" wire:navigate>
                        {{ __('welcome.login') }}
                    </flux:button>
                @endauth
            </div>
        </main>

        @fluxScripts
    </body>
</html>
