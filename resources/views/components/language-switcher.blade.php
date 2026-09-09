<flux:dropdown position="top" align="start">
    <flux:button
        {{ $attributes->class('w-full justify-start') }}
        size="sm"
        variant="subtle"
        icon="language"
        icon:trailing="chevron-up-down"
        data-test="language-switcher"
    >
        {{ __('language.'.app()->getLocale()) }}
    </flux:button>

    <flux:menu>
        @foreach (\App\Http\Middleware\SetLocale::SUPPORTED as $locale)
            <form method="POST" action="{{ route('locale.store', $locale) }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    class="w-full cursor-pointer"
                    :checked="app()->getLocale() === $locale"
                    data-test="language-{{ $locale }}"
                >
                    {{ __('language.'.$locale) }}
                </flux:menu.item>
            </form>
        @endforeach
    </flux:menu>
</flux:dropdown>
