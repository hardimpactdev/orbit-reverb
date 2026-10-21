<?php

declare(strict_types=1);

use App\Reverb\InvalidSnapshot;
use App\Reverb\RegistryRestartRequired;
use App\Reverb\ReverbManager;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;

it('adds apps through the same provider while keeping unaffected applications', function () {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(1, [registryApp('gateway')]));
    $gateway = $manager->findById('gateway');

    $manager->apply(registrySnapshot(2, [registryApp('gateway'), registryApp('hauzer')]));

    expect(app(ApplicationProvider::class))->toBe($manager)
        ->and($manager->findById('gateway'))->toBe($gateway)
        ->and($manager->findByKey('hauzer-key')->secret())->toBe('test-secret')
        ->and($manager->all())->toHaveCount(2);
});

it('removes an app from both protocol lookup paths', function () {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(1, [registryApp('gateway'), registryApp('hauzer')]));
    $manager->apply(registrySnapshot(2, [registryApp('gateway')]));

    expect(fn () => $manager->findById('hauzer'))->toThrow(InvalidApplication::class)
        ->and(fn () => $manager->findByKey('hauzer-key'))->toThrow(InvalidApplication::class)
        ->and($manager->findById('gateway')->id())->toBe('gateway');
});

it('rejects invalid snapshots atomically without exposing secrets', function () {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(1, [registryApp('gateway')]));
    $invalid = registrySnapshot(2, [registryApp('new', 'never-print-me'), registryApp('new')]);

    expect(fn () => $manager->apply($invalid))->toThrow(InvalidSnapshot::class, 'Invalid registry snapshot.')
        ->and($manager->revision())->toBe(1)
        ->and($manager->all())->toHaveCount(1);
});

it('accepts an exact replay without replacing apps', function () {
    $manager = app(ReverbManager::class);
    $snapshot = registrySnapshot(2, [registryApp('gateway')]);
    $manager->apply($snapshot);
    $gateway = $manager->findById('gateway');

    expect($manager->apply($snapshot))->toBeFalse()
        ->and($manager->findById('gateway'))->toBe($gateway);
});

it('refuses conflicting revisions and another registry history', function () {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(2, [registryApp('gateway')]));

    expect(fn () => $manager->apply(registrySnapshot(2, [])))->toThrow(RegistryRestartRequired::class)
        ->and(fn () => $manager->apply(array_replace(registrySnapshot(2, []), ['epoch' => 'restored'])))->toThrow(RegistryRestartRequired::class)
        ->and(fn () => $manager->apply(registrySnapshot(1, [])))->toThrow(RegistryRestartRequired::class)
        ->and($manager->revision())->toBe(2);
});

it('keeps numeric looking application identities strictly separate', function () {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(1, [registryApp('1', 'first'), registryApp('01', 'second')]));

    expect($manager->findById('01')->id())->toBe('01')
        ->and($manager->findByKey('01-key')->secret())->toBe('second')
        ->and($manager->findById('1')->secret())->toBe('first');
});

it('requires restart for a new epoch even when its revision advances', function (): void {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(2, [registryApp('gateway')]));
    $gateway = $manager->findById('gateway');

    expect(fn () => $manager->apply(array_replace(registrySnapshot(3, [registryApp('gateway')]), ['epoch' => 'new-history'])))
        ->toThrow(RegistryRestartRequired::class)
        ->and($manager->revision())->toBe(2)
        ->and($manager->findById('gateway'))->toBe($gateway);
});
