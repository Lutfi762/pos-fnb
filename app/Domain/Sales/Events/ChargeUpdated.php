<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A QRIS code on some till was paid, expired, or cancelled.
 *
 * Only the id travels: the payment screen that shows the code reads the rest
 * itself, so a stale or reordered message can never show the wrong state.
 */
class ChargeUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $outletId,
        public readonly string $chargeId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('outlet.'.$this->outletId.'.payments')];
    }

    public function broadcastAs(): string
    {
        return 'charge.updated';
    }

    /**
     * @return array{charge: string}
     */
    public function broadcastWith(): array
    {
        return ['charge' => $this->chargeId];
    }
}
