<?php

declare(strict_types=1);
use App\Reverb\Protocol\ManagedIncomingMessageHandler;

arch()->preset()->php();

// Stock Reverb Redis envelopes serialize Application. This adapter permits only that exact
// vendor class, then checks the current credential generation before stock dispatch.
arch()->preset()->security()->ignoring(ManagedIncomingMessageHandler::class);

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect('App\\Reverb')
    ->not->toUse(['dd', 'ddd', 'env', 'exit']);

arch('the Reverb integration declares strict types')
    ->expect('App\Reverb')
    ->toUseStrictTypes();
