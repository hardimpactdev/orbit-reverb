<?php

declare(strict_types=1);

namespace App\Reverb;

use RuntimeException;

final class InvalidSnapshot extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid registry snapshot.');
    }
}
