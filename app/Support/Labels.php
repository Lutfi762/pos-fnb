<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A whole translation file as an array, for pages that hand their strings to
 * JavaScript. Falls back to Indonesian when the current language has no copy
 * of the file, so the script never receives a bare key instead of an array.
 */
final class Labels
{
    /**
     * @return array<string, mixed>
     */
    public static function group(string $group): array
    {
        $labels = trans($group);

        if (! is_array($labels)) {
            $labels = trans($group, [], 'id');
        }

        return is_array($labels) ? $labels : [];
    }
}
