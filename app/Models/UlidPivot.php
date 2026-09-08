<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot rows carry ULIDs too, otherwise an offline device cannot create one.
 */
abstract class UlidPivot extends Pivot
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
