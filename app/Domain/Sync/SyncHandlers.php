<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\Handlers\SyncCashMovement;
use App\Domain\Sync\Handlers\SyncCashPayment;
use App\Domain\Sync\Handlers\SyncDraftOrder;
use App\Domain\Sync\Handlers\SyncShift;

/**
 * Which handler applies which kind of queued command.
 */
class SyncHandlers
{
    /** @var array<string, class-string<SyncHandler>> */
    public const MAP = [
        // "draft_order" is what the first offline release queued. Terminals
        // may still hold some, so the old name keeps working.
        'draft_order' => SyncDraftOrder::class,
        'order.saved' => SyncDraftOrder::class,
        'payment.recorded' => SyncCashPayment::class,
        'shift.opened' => SyncShift::class,
        'shift.closed' => SyncShift::class,
        'cash.moved' => SyncCashMovement::class,
    ];

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::MAP);
    }

    public function for(string $type): SyncHandler
    {
        abort_unless(isset(self::MAP[$type]), 422, __('sync.unknown_type'));

        return app(self::MAP[$type]);
    }
}
