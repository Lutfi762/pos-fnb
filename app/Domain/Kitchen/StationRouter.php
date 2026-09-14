<?php

declare(strict_types=1);

namespace App\Domain\Kitchen;

use App\Domain\Catalog\Models\Product;
use App\Domain\Kitchen\Enums\Station;

/**
 * Decides which station a product goes to, and how long it is given.
 *
 * The category carries the rule, because a menu is edited by the dish and
 * routed by the section. A product may still override it for the odd item
 * that sits in the wrong section, like a hot chocolate under drinks.
 */
class StationRouter
{
    public const DEFAULT_PREP_MINUTES = 10;

    public function stationFor(Product $product): Station
    {
        $station = $product->station ?? $product->category?->station;

        return Station::tryFrom((string) $station) ?? Station::Kitchen;
    }

    /**
     * Target minutes for a line of this product.
     */
    public function prepMinutesFor(Product $product): int
    {
        $minutes = $product->category?->prep_minutes;

        return $minutes === null || $minutes < 1
            ? self::DEFAULT_PREP_MINUTES
            : (int) $minutes;
    }
}
