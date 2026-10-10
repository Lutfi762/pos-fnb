<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\ScreenLock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Screen locked')] class extends Component {
    public string $userId = '';

    public string $pin = '';

    public function mount(): void
    {
        $this->userId = (string) Auth::id();
    }

    /**
     * Staff who can take over this terminal: everyone rostered at this outlet
     * who has a PIN set.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return app(CurrentOutlet::class)
            ->getOrFail()
            ->users()
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->orderBy('name')
            ->get();
    }

    public function unlock(): void
    {
        $this->validate([
            'userId' => ['required'],
            'pin' => ['required', 'digits_between:4,8'],
        ]);

        $key = 'unlock|'.app(CurrentOutlet::class)->id().'|'.$this->userId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'pin' => __('pin.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = $this->staff->firstWhere('id', $this->userId);

        if (! $user instanceof User || ! $user->verifyPin($this->pin)) {
            RateLimiter::hit($key, 60);

            $this->reset('pin');

            throw ValidationException::withMessages(['pin' => __('pin.invalid')]);
        }

        RateLimiter::clear($key);

        // Handing the terminal to a different person is a real login.
        // Auth::login() rotates the session id for us, so the previous
        // shift's session cannot be replayed.
        if ($user->isNot(Auth::user())) {
            $outlet = app(CurrentOutlet::class)->getOrFail();

            Auth::login($user);

            // Auth::login() wipes the session, but the terminal has not moved.
            app(CurrentOutlet::class)->set($outlet, $user);
        }

        app(ScreenLock::class)->unlock();

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('pin.title')" :description="__('pin.description')" />

    <form wire:submit="unlock" class="flex flex-col gap-6">
        <flux:select wire:model="userId" :label="__('pin.staff')" required>
            @foreach ($this->staff as $person)
                <flux:select.option value="{{ $person->id }}">{{ $person->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model="pin"
            :label="__('pin.pin')"
            type="password"
            inputmode="numeric"
            autocomplete="off"
            maxlength="8"
            required
            autofocus
            data-test="pin-input"
        />

        <flux:button variant="primary" type="submit" class="w-full" data-test="unlock-button">
            {{ __('pin.unlock') }}
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <flux:button type="submit" variant="subtle" class="w-full" data-test="logout-button">
            {{ __('Log out') }}
        </flux:button>
    </form>
</div>
