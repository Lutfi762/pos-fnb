<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Every domain table uses a ULID primary key so a tablet that is offline can
 * mint ids that will never collide with the ones the server hands out.
 */
abstract class DomainModel extends Model
{
    use HasUlids;

    protected $guarded = ['id'];
}
