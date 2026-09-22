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

    expect(File::get(resource_path('js/app.js')))
        ->toContain("navigator.serviceWorker.register('/service-worker.js')")
        ->and(File::get(resource_path('js/offline-drafts.js')))
        ->toContain("const DATABASE = 'meja-offline'", "'/sync/draft-orders'", 'previous.operation_key', 'left.sequence - right.sequence', "(await send(operation)) !== 'sent'", 'blocked_status', 'offline-draft-blocked', 'offline-draft-discard', 'offline-draft-sent', 'data-offline-draft-save');

    expect(File::get(resource_path('views/pages/pos/summary.blade.php')))
        ->toContain('offline-draft-status', 'offline-draft-sent.window', 'offline-draft-discard', 'window.location.reload()');
});
