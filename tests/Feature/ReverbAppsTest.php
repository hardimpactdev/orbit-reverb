<?php

use App\Reverb\ReverbManager;
use Illuminate\Support\Facades\Artisan;
use Laravel\Reverb\Contracts\ApplicationProvider;

it('boots with the managed provider and waits for a fresh registry', function () {
    $provider = app(ApplicationProvider::class);
    expect($provider)->toBeInstanceOf(ReverbManager::class)
        ->and($provider->all())->toBeEmpty()
        ->and($provider->ready())->toBeFalse()
        ->and(config('reverb.apps.provider'))->toBe('orbit')
        ->and(config('reverb.apps.apps'))->toBeNull()
        ->and(Artisan::all())->toHaveKey('orbit:reverb:start');
});

it('refuses to start without a restricted registry configuration', function () {
    config()->set('registry.registry_token', null);
    expect(fn () => Artisan::call('orbit:reverb:start'))->toThrow(RuntimeException::class, 'Reverb registry configuration is incomplete.');
});
