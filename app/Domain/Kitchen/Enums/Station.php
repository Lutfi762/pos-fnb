<?php

declare(strict_types=1);

namespace App\Domain\Kitchen\Enums;

enum Station: string
{
    case Kitchen = 'kitchen';
    case Bar = 'bar';

    public function label(): string
    {
        return __('kitchen.station.'.$this->value);
    }
}
