<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\SaveStaff;
use App\Domain\Identity\StaffPins;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Staff')] class extends Component {
    #[Url(as: 'nonaktif', except: false)]
    public bool $withInactive = false;

    /** The person being edited; null while adding someone new. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'cashier';

    public string $password = '';

    public string $pin = '';

    /** @var list<string> */
    public array $outletIds = [];

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return SaveStaff::roleOf(auth()->user(), $this->outlet) === Role::Owner;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        $outletId = $this->outlet->getKey();

        return User::query()
            ->with('outlets')
            ->whereHas('outlets', fn ($query) => $query->where('outlets.id', $outletId))
            ->when(! $this->withInactive, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<Role>
     */
    #[Computed]
    public function roles(): array
    {
        return SaveStaff::assignable(auth()->user(), $this->outlet);
    }

    /**
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->outletIds = [$this->outlet->getKey()];
        $this->role = Role::Cashier->value;

        Flux::modal('staff-form')->show();
    }

    public function edit(int $userId): void
    {
        $user = $this->staff->firstWhere('id', $userId);
        abort_unless($user instanceof User && SaveStaff::canManage(auth()->user(), $this->outlet, $user), 403);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = ($user->isOwner() ? Role::Owner : $user->roleAt($this->outlet))?->value ?? Role::Cashier->value;
        $this->outletIds = $user->outlets->modelKeys();

        Flux::modal('staff-form')->show();
    }

    public function save(): void
    {
        $creating = $this->editingId === null;
        $this->resetErrorBag();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role' => ['required', Rule::in(array_map(fn (Role $role): string => $role->value, $this->roles))],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],
            'pin' => [$creating ? 'required' : 'nullable', 'digits:'.StaffPins::LENGTH],
            'outletIds' => ['array'],
            'outletIds.*' => ['string'],
        ], attributes: [
            'name' => __('staff.name'),
            'email' => __('staff.email'),
            'role' => __('staff.role'),
            'password' => __('staff.password'),
            'pin' => __('staff.pin'),
        ]);

        $staff = $creating ? null : User::query()->with('outlets')->findOrFail($this->editingId);

        $saved = app(SaveStaff::class)->handle(auth()->user(), $this->outlet, [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'password' => $this->password,
            'pin' => $this->pin,
            'outlets' => array_values($this->outletIds),
        ], $staff);

        Flux::modal('staff-form')->close();
        Flux::toast(variant: 'success', text: __($creating ? 'staff.created' : 'staff.updated', ['name' => $saved->name]));

        $this->resetForm();
        unset($this->staff);
    }

    public function toggleActive(int $userId): void
    {
        $user = User::query()->with('outlets')->findOrFail($userId);
        $active = ! $user->is_active;

        app(SaveStaff::class)->setActive(auth()->user(), $this->outlet, $user, $active);

        Flux::toast(variant: 'success', text: __($active ? 'staff.activated' : 'staff.deactivated', ['name' => $user->name]));

        unset($this->staff);
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'password', 'pin', 'outletIds');
        $this->resetErrorBag();
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('staff.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">{{ __('staff.subtitle', ['outlet' => $this->outlet->name]) }}</flux:text>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="create" class="min-h-11" data-test="staff-add">
            {{ __('staff.add') }}
        </flux:button>
    </div>

    <flux:checkbox wire:model.live="withInactive" :label="__('staff.show_inactive')" data-test="staff-show-inactive" />

    @if ($this->staff->isEmpty())
        <x-empty-state icon="users" :heading="__('staff.empty_heading')" :description="__('staff.empty_description')" />
    @else
        <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="report-table" data-test="staff-table">
                <thead>
                    <tr>
                        <th scope="col" class="text-start">{{ __('staff.name') }}</th>
                        <th scope="col" class="text-start">{{ __('staff.role') }}</th>
                        <th scope="col" class="text-start">{{ __('staff.pin') }}</th>
                        <th scope="col" class="text-start">{{ __('staff.status') }}</th>
                        <th scope="col"><span class="sr-only">{{ __('staff.edit') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->staff as $person)
                        @php($personRole = $person->isOwner() ? \App\Domain\Identity\Enums\Role::Owner : $person->roleAt($this->outlet))
                        <tr wire:key="staff-{{ $person->id }}" data-test="staff-row-{{ $person->id }}" @class(['text-content-muted' => ! $person->is_active])>
                            <th scope="row" class="text-start font-normal">
                                <span class="font-medium text-content">{{ $person->name }}</span>
                                @if ($person->is(auth()->user()))
                                    <flux:badge size="sm" class="ms-1">{{ __('staff.you') }}</flux:badge>
                                @endif
                                <span class="block text-sm text-content-muted">{{ $person->email }}</span>
                            </th>
                            <td class="text-start">{{ $personRole?->label() }}</td>
                            <td class="text-start">{{ $person->pin_hash === null ? __('staff.no_pin') : __('staff.has_pin') }}</td>
                            <td class="text-start">
                                <flux:badge size="sm" :color="$person->is_active ? 'green' : 'zinc'">
                                    {{ $person->is_active ? __('staff.active') : __('staff.inactive') }}
                                </flux:badge>
                            </td>
                            <td>
                                @if (\App\Domain\Identity\SaveStaff::canManage(auth()->user(), $this->outlet, $person))
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $person->id }})" class="min-h-11" data-test="staff-edit-{{ $person->id }}" :aria-label="__('staff.edit_named', ['name' => $person->name])">
                                            <span class="max-sm:sr-only">{{ __('staff.edit') }}</span>
                                        </flux:button>

                                        <flux:button
                                            size="sm"
                                            :variant="$person->is_active ? 'ghost' : 'filled'"
                                            wire:click="toggleActive({{ $person->id }})"
                                            :wire:confirm="$person->is_active ? __('staff.deactivate_confirm', ['name' => $person->name]) : null"
                                            class="min-h-11"
                                            data-test="staff-toggle-{{ $person->id }}"
                                        >
                                            {{ $person->is_active ? __('staff.deactivate') : __('staff.activate') }}
                                        </flux:button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <flux:modal name="staff-form" class="w-full max-w-lg" @close="$wire.resetForm()">
        <form wire:submit="save" class="flex flex-col gap-5" data-test="staff-form">
            <flux:heading size="lg">{{ $editingId === null ? __('staff.add') : __('staff.edit') }}</flux:heading>

            <flux:input wire:model="name" :label="__('staff.name')" autocomplete="off" required data-test="staff-name" />
            <flux:input wire:model="email" type="email" :label="__('staff.email')" autocomplete="off" required data-test="staff-email" />

            <flux:select wire:model.live="role" :label="__('staff.role')" data-test="staff-role">
                @foreach ($this->roles as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->isOwner && $this->outlets->count() > 1)
                <flux:checkbox.group wire:model="outletIds" :label="__('staff.outlets')" :description="__('staff.outlets_hint')" data-test="staff-outlets">
                    @foreach ($this->outlets as $option)
                        <flux:checkbox :value="$option->id" :label="$option->name" />
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="outlets" />
            @endif

            <flux:input
                wire:model="password"
                type="password"
                :label="__('staff.password')"
                :description="$editingId === null ? __('staff.password_hint_new') : __('staff.password_hint_edit')"
                autocomplete="new-password"
                data-test="staff-password"
            />

            <flux:input
                wire:model="pin"
                type="password"
                inputmode="numeric"
                maxlength="{{ \App\Domain\Identity\StaffPins::LENGTH }}"
                :label="__('staff.pin')"
                :description="$editingId === null ? __('staff.pin_hint_new', ['length' => \App\Domain\Identity\StaffPins::LENGTH]) : __('staff.pin_hint_edit')"
                autocomplete="off"
                class="tabular-nums"
                data-test="staff-pin"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button class="min-h-11">{{ __('staff.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="min-h-11" data-test="staff-save">{{ __('staff.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
