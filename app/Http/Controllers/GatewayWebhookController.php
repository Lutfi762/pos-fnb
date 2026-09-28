<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Models\GatewayWebhook;
use App\Domain\Sales\ReceiveWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the payment gateway reports that a guest paid.
 */
class GatewayWebhookController
{
    public function __invoke(Request $request, string $provider, ReceiveWebhook $receive): JsonResponse
    {
        $webhook = $receive->handle($provider, $request);

        if ($webhook->status === GatewayWebhook::REJECTED) {
            return response()->json(['received' => false], 401);
        }

        return response()->json(['received' => true]);
    }
}
