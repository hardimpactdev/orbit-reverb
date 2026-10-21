<?php

declare(strict_types=1);

namespace App\Reverb;

use RuntimeException;

final class RegistryRestartRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Registry history changed; restart required.');
    }
}
