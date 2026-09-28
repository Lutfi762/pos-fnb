<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Sales\Enums\ChargeStatus;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Gateway\SettlementLine;
use App\Domain\Sales\Models\GatewaySettlement;
use App\Domain\Sales\Models\PaymentCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lines the gateway's record of a day up against ours.
 *
 * Three ways it can disagree, and each one means something different: the
 * gateway has money we never put on a bill (unknown), we put money on a bill
 * the gateway never collected (missing), or both have it for different
 * amounts (mismatch). Running it twice for the same day gives the same
 * answer; lines are stored by the gateway's own transaction id.
 */
class ReconcileSettlement
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{lines: int, matched: int, mismatch: int, unknown: int, missing: int, gross: int, fees: int, net: int}
     */
    public function run(CarbonImmutable $date): array
    {
        [$from, $to] = $this->bounds($date);

        $lines = $this->gateway->settlements($from, $to);

        $charges = PaymentCharge::query()
            ->with('payment')
            ->where('provider', $this->gateway->name())
            ->whereIn('reference', array_filter(array_map(fn (SettlementLine $line): ?string => $line->reference, $lines)))
            ->get()
            ->keyBy('reference');

        DB::transaction(function () use ($lines, $charges, $date): void {
            foreach ($lines as $line) {
                $charge = $line->reference === null ? null : $charges->get($line->reference);

                $result = match (true) {
                    ! $charge instanceof PaymentCharge => GatewaySettlement::UNKNOWN,
                    $charge->paid_amount === $line->amount => GatewaySettlement::MATCHED,
                    default => GatewaySettlement::AMOUNT_MISMATCH,
                };

                GatewaySettlement::query()->updateOrCreate(
                    ['provider' => $this->gateway->name(), 'provider_transaction_id' => $line->providerTransactionId],
                    [
                        'outlet_id' => $charge?->outlet_id,
                        'transaction_date' => $date->toDateString(),
                        'reference' => $line->reference,
                        'amount' => $line->amount,
                        'fee' => $line->fee,
                        'net_amount' => $line->netAmount,
                        'settlement_status' => $line->settlementStatus,
                        'transacted_at' => $line->transactedAt,
                        'payment_id' => $charge?->payment?->getKey(),
                        'result' => $result,
                    ],
                );

                if ($charge instanceof PaymentCharge && $result === GatewaySettlement::MATCHED && $charge->payment !== null) {
                    $charge->payment->update([
                        'settled_amount' => $line->netAmount,
                        'settled_fee' => $line->fee,
                        'settled_at' => now(),
                    ]);
                }
            }
        });

        $stored = GatewaySettlement::query()->where('provider', $this->gateway->name())->whereDate('transaction_date', $date->toDateString())->get();

        $summary = [
            'lines' => $stored->count(),
            'matched' => $stored->where('result', GatewaySettlement::MATCHED)->count(),
            'mismatch' => $stored->where('result', GatewaySettlement::AMOUNT_MISMATCH)->count(),
            'unknown' => $stored->where('result', GatewaySettlement::UNKNOWN)->count(),
            'missing' => $this->missing($date)->count(),
            'gross' => (int) $stored->sum('amount'),
            'fees' => (int) $stored->sum('fee'),
            'net' => (int) $stored->sum('net_amount'),
        ];

        $this->audit->record('qris.reconciled', after: $summary + ['date' => $date->toDateString()]);

        return $summary;
    }

    /**
     * Codes we counted as paid that the gateway's report for the day lacks.
     *
     * @return Collection<int, PaymentCharge>
     */
    public function missing(CarbonImmutable $date): Collection
    {
        [$from, $to] = $this->bounds($date);

        // A payment just before midnight can land on the next day's report.
        $reported = GatewaySettlement::query()
            ->where('provider', $this->gateway->name())
            ->whereNotNull('reference')
            ->whereBetween('transaction_date', [$date->subDay()->toDateString(), $date->addDay()->toDateString()])
            ->pluck('reference');

        return PaymentCharge::query()
            ->with(['order', 'payment'])
            ->where('provider', $this->gateway->name())
            ->whereIn('status', [ChargeStatus::Paid, ChargeStatus::Unapplied])
            ->whereBetween('paid_at', [$from, $to])
            ->whereNotIn('reference', $reported)
            ->orderBy('paid_at')
            ->get();
    }

    /**
     * ponytail: a calendar day in the app timezone. Moves to the outlet's
     * business day with the rest of the reports.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(CarbonImmutable $date): array
    {
        $day = $date->setTimezone(config('app.timezone'));

        return [$day->startOfDay(), $day->endOfDay()];
    }
}
