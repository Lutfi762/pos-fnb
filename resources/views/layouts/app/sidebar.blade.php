<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <meta name="meja-app" content="1">
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <x-connection-status class="mx-2 hidden lg:block" />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate data-test="nav-dashboard">
                        {{ __('dashboard.nav') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate data-test="nav-reports">
                        {{ __('reports.title') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="calculator" :href="route('pos.index')" :current="request()->routeIs('pos.index')" wire:navigate>
                        {{ __('pos.title') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="banknotes" :href="route('pos.shift')" :current="request()->routeIs('pos.shift')" wire:navigate>
                        {{ __('shifts.title') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="fire" :href="route('kitchen.index')" :current="request()->routeIs('kitchen.*')" wire:navigate>
                        {{ __('kitchen.title') }}
                    </flux:sidebar.item>

                    @php($role = app(\App\Domain\Identity\CurrentOutlet::class)->roleFor(auth()->user()))

                    @if ($role?->managesOutlet())
                        <flux:sidebar.item icon="squares-2x2" :href="route('catalog.index')" :current="request()->routeIs('catalog.*')" wire:navigate>
                            {{ __('catalog.title') }}
                        </flux:sidebar.item>
                    @endif

                    @if ($role?->managesOutlet())
                        <flux:sidebar.item icon="arrow-path-rounded-square" :href="route('sync.index')" :current="request()->routeIs('sync.*')" wire:navigate>
                            {{ __('sync.nav') }}
                        </flux:sidebar.item>
                    @endif

                    @if ($role?->managesOutlet())
                        <flux:sidebar.item icon="qr-code" :href="route('payments.review')" :current="request()->routeIs('payments.*')" wire:navigate data-test="nav-payments-review">
                            {{ __('qris.review.nav') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="cog-6-tooth" :href="route('outlet.settings')" :current="request()->routeIs('outlet.settings')" wire:navigate data-test="nav-outlet-settings">
                            {{ __('outlet.settings.nav') }}
                        </flux:sidebar.item>
                    @endif

                    @if ($role?->managesStock())
                        <flux:sidebar.item icon="cube" :href="route('stock.index')" :current="request()->routeIs('stock.index')" wire:navigate>
                            {{ __('inventory.title') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="truck" :href="route('purchases.index')" :current="request()->routeIs('purchases.*')" wire:navigate>
                            {{ __('purchasing.title') }}
                        </flux:sidebar.item>
                    @endif

                    @if ($role?->logsWaste())
                        <flux:sidebar.item icon="trash" :href="route('stock.waste')" :current="request()->routeIs('stock.waste')" wire:navigate data-test="nav-waste">
                            {{ __('waste.nav') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <form method="POST" action="{{ route('lock.store') }}" class="w-full">
                    @csrf
                    <flux:sidebar.item as="button" type="submit" icon="lock-closed" class="w-full cursor-pointer" data-test="lock-button">
                        {{ __('pin.lock') }}
                    </flux:sidebar.item>
                </form>
            </flux:sidebar.nav>

            <x-language-switcher class="mx-2 mb-1" />

            <x-outlet-badge class="mx-2 mb-2" />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <x-connection-status class="ms-1" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
