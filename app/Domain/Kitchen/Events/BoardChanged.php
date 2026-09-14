<?php

declare(strict_types=1);

namespace App\Domain\Kitchen\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something on the board moved.
 *
 * The payload is deliberately empty: every screen refetches its own tickets
 * when this lands. A board is small, and a nudge cannot go stale the way a
 * serialised ticket can when two stations tap at the same moment.
 *
 * Broadcast now rather than queued, because a queue worker is one more
 * process for an outlet to forget to start.
 */
class BoardChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly string $outletId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('outlet.'.$this->outletId.'.kitchen')];
    }

    public function broadcastAs(): string
    {
        return 'board.changed';
    }
}
