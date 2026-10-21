<?php

declare(strict_types=1);
use HardImpact\ReverbManager\Protocol\ManagedIncomingMessageHandler;

arch()->preset()->php();

// Stock Reverb Redis envelopes serialize Application. This adapter permits only that exact
// vendor class, then checks the current credential generation before stock dispatch.
arch()->preset()->security()->ignoring(ManagedIncomingMessageHandler::class);

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('HardImpact\ReverbManager')
    ->toUseStrictTypes();
