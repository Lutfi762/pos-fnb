<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('publishes an installable PWA manifest and asset-only worker', function (): void {
    /** @var array{name: string, display: string, icons: list<array{src: string, sizes: string}>} $manifest */
    $manifest = json_decode(File::get(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Meja')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'][0])->toMatchArray(['src' => '/icon-192.png', 'sizes' => '192x192'])
        ->and(File::exists(public_path('icon-192.png')))->toBeTrue()
        ->and(File::exists(public_path('icon-512.png')))->toBeTrue()
        ->and(File::get(public_path('service-worker.js')))->toContain("request.method !== 'GET'", "request.mode === 'navigate'");
});

it('links the manifest and worker entry point from app pages', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', escape: false);

    expect(File::get(resource_path('js/app.js')))->toContain('installOffline()')
        ->and(File::get(resource_path('js/offline/index.js')))
        ->toContain("navigator.serviceWorker.register('/service-worker.js')", "type: 'cache-shell'", 'data-offline-handoff')
        ->and(File::get(resource_path('js/offline/outbox.js')))
        ->toContain("'/sync/operations'", 'left.sequence - right.sequence', 'sequence_gap', 'operation.blocked')
        ->and(File::get(public_path('service-worker.js')))
        ->toContain("const SHELL_URL = '/pos/offline'", "'cache-shell'", '!response.redirected');

    expect(File::get(resource_path('views/pages/pos/summary.blade.php')))
        ->toContain('data-offline-handoff', 'offline-handoff-status');
});
