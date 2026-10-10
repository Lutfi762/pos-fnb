<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Gateway\GatewayException;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Models\GatewaySettlement;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sales\QrisPayments;
use App\Domain\Sales\ReconcileSettlement;
use App\Domain\Sales\StaticQrisReview;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Payment review')] class extends Component {
    #[Url(as: 'tanggal', except: '')]
    public string $date = '';

    /** @var array<string, string> */
    public array $notes = [];

    /** @var array<string, int>|null */
    public ?array $summary = null;

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    #[Computed]
    public function day(): CarbonImmutable
    {
        try {
            return $this->date === '' ? CarbonImmutable::yesterday() : CarbonImmutable::parse($this->date)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::yesterday();
        }
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function unverified(): Collection
    {
        return Payment::query()
            ->with(['order', 'cashier'])
            ->where('verification', Payment::UNVERIFIED)
            ->whereHas('order', fn ($query) => $query->where('outlet_id', $this->outlet->getKey()))
            ->oldest('paid_at')
            ->get();
    }

    /**
     * @return Collection<int, PaymentCharge>
     */
    #[Computed]
    public function attention(): Collection
    {
        return PaymentCharge::query()
            ->with(['order', 'cashier'])
            ->where('outlet_id', $this->outlet->getKey())
            ->where('unapplied_amount', '>', 0)
            ->whereNull('resolved_at')
            ->oldest('paid_at')
            ->get();
    }

    /**
     * @return Collection<int, GatewaySettlement>
     */
    #[Computed]
    public function settlements(): Collection
    {
        return GatewaySettlement::query()
            ->with('payment.order')
            ->where('provider', app(PaymentGateway::class)->name())
            ->whereDate('transaction_date', $this->day->toDateString())
            ->where(fn ($query) => $query->where('outlet_id', $this->outlet->getKey())->orWhereNull('outlet_id'))
            ->orderBy('transacted_at')
            ->get();
    }

    /**
     * @return Collection<int, PaymentCharge>
     */
    #[Computed]
    public function missing(): Collection
    {
        if ($this->settlements->isEmpty()) {
            return collect();
        }

        return app(ReconcileSettlement::class)->missing($this->day)
            ->where('outlet_id', $this->outlet->getKey())
            ->values();
    }

    public function verify(string $paymentId): void
    {
        $payment = $this->unverified->firstWhere('id', $paymentId);
        abort_unless($payment instanceof Payment, 404);

        app(StaticQrisReview::class)->verify($payment, auth()->user(), $this->notes[$paymentId] ?? null);

        unset($this->notes[$paymentId], $this->unverified);
        Flux::toast(variant: 'success', text: __('qris.review.verified', ['amount' => Money::format($payment->amount)]));
    }

    public function reject(string $paymentId): void
    {
        $payment = $this->unverified->firstWhere('id', $paymentId);
        abort_unless($payment instanceof Payment, 404);
        $this->resetErrorBag('notes.'.$paymentId);

        if (trim($this->notes[$paymentId] ?? '') === '') {
            $this->addError('notes.'.$paymentId, __('qris.note_required'));

            return;
        }

        app(StaticQrisReview::class)->reject($payment, auth()->user(), $this->notes[$paymentId]);

        unset($this->notes[$paymentId], $this->unverified);
        Flux::toast(variant: 'warning', text: __('qris.review.rejected', ['amount' => Money::format($payment->amount)]));
    }

    public function resolve(string $chargeId): void
    {
        $charge = $this->attention->firstWhere('id', $chargeId);
        abort_unless($charge instanceof PaymentCharge, 404);
        $this->resetErrorBag('notes.'.$chargeId);

        if (trim($this->notes[$chargeId] ?? '') === '') {
            $this->addError('notes.'.$chargeId, __('qris.note_required'));

            return;
        }

        app(QrisPayments::class)->resolve($charge, auth()->user(), $this->notes[$chargeId]);

        unset($this->notes[$chargeId], $this->attention);
        Flux::toast(variant: 'success', text: __('qris.review.resolved'));
    }

    public function runSettlement(): void
    {
        $this->resetErrorBag('date');

        try {
            $this->summary = app(ReconcileSettlement::class)->run($this->day);
        } catch (GatewayException $exception) {
            $this->addError('date', $exception->getMessage());

            return;
        }

        unset($this->settlements, $this->missing);
    }

    public function updatedDate(): void
    {
        $this->summary = null;
        unset($this->day, $this->settlements, $this->missing);
    }
}; ?>

<div class="flex w-full flex-col gap-8">
    <div>
        <flux:heading size="xl" class="font-display">{{ __('qris.review.title') }}</flux:heading>
        <flux:text class="mt-1 text-content-muted">{{ __('qris.review.subtitle', ['outlet' => $this->outlet->name]) }}</flux:text>
    </div>

    {{-- Static QRIS: keyed by a cashier, not yet seen in the account. --}}
    <section class="flex flex-col gap-3" aria-labelledby="static-heading">
        <flux:heading id="static-heading" size="lg">{{ __('qris.review.static_heading') }}</flux:heading>

        @if ($this->unverified->isEmpty())
            <flux:text data-test="static-empty">{{ __('qris.review.static_empty') }}</flux:text>
        @else
            <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
                <table class="report-table" data-test="static-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('qris.review.bill') }}</th>
                            <th scope="col">{{ __('qris.review.time') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.cashier') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.reference') }}</th>
                            <th scope="col">{{ __('qris.review.amount') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.proof') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.note') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('qris.review.verify') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->unverified as $payment)
                            <tr wire:key="sv-{{ $payment->id }}">
                                <th scope="row" class="text-start font-normal">{{ $payment->order?->number }}</th>
                                <td>{{ $payment->paid_at->translatedFormat('d M H:i') }}</td>
                                <td class="text-start">{{ $payment->cashier?->name }}</td>
                                <td class="text-start">{{ $payment->reference ?? '-' }}</td>
                                <td><x-money :amount="$payment->amount" /></td>
                                <td class="text-start">
                                    @if ($payment->proof_path)
                                        <flux:link :href="route('payments.proof', $payment)" target="_blank">{{ __('qris.review.view_proof') }}</flux:link>
                                    @else
                                        <span class="text-content-muted">{{ __('qris.review.no_proof') }}</span>
                                    @endif
                                </td>
                                <td class="text-start">
                                    <flux:input size="sm" wire:model="notes.{{ $payment->id }}" :placeholder="__('qris.review.reject_note')" class="min-w-48" />
                                </td>
                                <td>
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="primary" class="min-h-11" wire:click="verify('{{ $payment->id }}')" data-test="verify-{{ $payment->id }}">{{ __('qris.review.verify') }}</flux:button>
                                        <flux:button size="sm" variant="danger" class="min-h-11" wire:click="reject('{{ $payment->id }}')" data-test="reject-{{ $payment->id }}">{{ __('qris.review.reject') }}</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Dynamic QRIS paid after the bill was already settled. --}}
    <section class="flex flex-col gap-3" aria-labelledby="attention-heading">
        <div>
            <flux:heading id="attention-heading" size="lg">{{ __('qris.review.attention_heading') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">{{ __('qris.review.attention_hint') }}</flux:text>
        </div>

        @if ($this->attention->isEmpty())
            <flux:text data-test="attention-empty">{{ __('qris.review.attention_empty') }}</flux:text>
        @else
            <div class="relative overflow-x-auto rounded-xl border border-pending bg-pending-surface">
                <table class="report-table" data-test="attention-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('qris.review.bill') }}</th>
                            <th scope="col">{{ __('qris.review.time') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.reference') }}</th>
                            <th scope="col">{{ __('qris.review.unapplied') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.note') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('qris.review.resolve') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->attention as $charge)
                            <tr wire:key="at-{{ $charge->id }}">
                                <th scope="row" class="text-start font-normal">{{ $charge->order?->number }}</th>
                                <td>{{ $charge->paid_at?->translatedFormat('d M H:i') }}</td>
                                <td class="text-start">{{ $charge->provider_payment_id ?? $charge->reference }}</td>
                                <td><x-money :amount="$charge->unapplied_amount" /></td>
                                <td class="text-start">
                                    <flux:input size="sm" wire:model="notes.{{ $charge->id }}" :placeholder="__('qris.review.resolve_note')" class="min-w-56" />
                                </td>
                                <td>
                                    <flux:button size="sm" class="min-h-11" wire:click="resolve('{{ $charge->id }}')" data-test="resolve-{{ $charge->id }}">{{ __('qris.review.resolve') }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- The gateway's report for a day against what the tills recorded. --}}
    <section class="flex flex-col gap-3" aria-labelledby="settlement-heading">
        <div>
            <flux:heading id="settlement-heading" size="lg">{{ __('qris.review.settlement_heading') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('qris.review.settlement_hint') }}
                {{ __('qris.review.gateway_label', ['name' => app(\App\Domain\Sales\Gateway\PaymentGateway::class)->name()]) }}
            </flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input type="date" wire:model.live="date" :label="__('qris.review.date')" class="max-w-44" :max="now()->toDateString()" />
            <flux:button variant="primary" icon="arrows-right-left" class="min-h-11" wire:click="runSettlement" wire:loading.attr="disabled" data-test="run-settlement">{{ __('qris.review.run') }}</flux:button>
        </div>

        @if ($summary !== null)
            <flux:callout icon="information-circle" data-test="settlement-summary">
                <flux:callout.text>{{ __('qris.review.ran', $summary) }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->settlements->isEmpty())
            <flux:text class="text-content-muted">{{ __('qris.review.settlement_empty') }}</flux:text>
        @else
            <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
                <table class="report-table" data-test="settlement-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('qris.review.reference') }}</th>
                            <th scope="col">{{ __('qris.review.time') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.bill') }}</th>
                            <th scope="col">{{ __('qris.review.gross') }}</th>
                            <th scope="col">{{ __('qris.review.fees') }}</th>
                            <th scope="col">{{ __('qris.review.net') }}</th>
                            <th scope="col" class="text-start">{{ __('qris.review.result') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->settlements as $line)
                            <tr wire:key="st-{{ $line->id }}">
                                <th scope="row" class="text-start font-normal">{{ $line->reference ?? $line->provider_transaction_id }}</th>
                                <td>{{ $line->transacted_at?->format('H:i') }}</td>
                                <td class="text-start">{{ $line->payment?->order?->number ?? '-' }}</td>
                                <td><x-money :amount="$line->amount" /></td>
                                <td><x-money :amount="$line->fee" /></td>
                                <td><x-money :amount="$line->net_amount" /></td>
                                <td @class([
                                    'text-start',
                                    'text-done-text' => $line->result === \App\Domain\Sales\Models\GatewaySettlement::MATCHED,
                                    'text-danger-text font-semibold' => $line->result !== \App\Domain\Sales\Models\GatewaySettlement::MATCHED,
                                ])>{{ __('qris.review.results.'.$line->result) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-line-strong font-semibold">
                            <th scope="row" colspan="3" class="text-start">{{ __('qris.review.net') }}</th>
                            <td><x-money :amount="$this->settlements->sum('amount')" /></td>
                            <td><x-money :amount="$this->settlements->sum('fee')" /></td>
                            <td><x-money :amount="$this->settlements->sum('net_amount')" data-test="settlement-net" /></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($this->missing->isNotEmpty())
                <flux:heading size="md" class="!text-danger-text">{{ __('qris.review.missing_heading') }}</flux:heading>
                <ul class="flex flex-col gap-1" data-test="settlement-missing">
                    @foreach ($this->missing as $charge)
                        <li wire:key="ms-{{ $charge->id }}" class="flex justify-between gap-3 rounded-lg border border-danger bg-danger-surface px-3 py-2 text-danger-text">
                            <span>{{ $charge->order?->number }} &middot; {{ $charge->reference }}</span>
                            <x-money :amount="$charge->paid_amount" />
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>
</div>
