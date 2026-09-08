<?php

declare(strict_types=1);

it('boots the application with the Meja configuration', function (): void {
    expect(config('app.name'))->toBe('Meja')
        ->and(config('app.timezone'))->toBe('Asia/Jakarta')
        ->and(config('app.locale'))->toBe('id');
});
