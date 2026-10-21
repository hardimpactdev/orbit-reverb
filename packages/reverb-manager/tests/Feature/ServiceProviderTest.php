<?php

declare(strict_types=1);

use HardImpact\ReverbManager\ReverbManager;
use Illuminate\Support\Facades\Artisan;

it('registers the managed provider and startup command', function (): void {
    expect(app(ReverbManager::class))->toBe(app(ReverbManager::class))
        ->and(config('reverb-manager.reconcile_interval'))->toBe(30)
        ->and(Artisan::all())->toHaveKey('orbit:reverb:start');
});
