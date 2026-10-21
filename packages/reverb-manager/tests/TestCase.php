<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager\Tests;

use HardImpact\ReverbManager\ReverbManagerServiceProvider;
use Laravel\Reverb\ApplicationManagerServiceProvider;
use Laravel\Reverb\ReverbServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ApplicationManagerServiceProvider::class,
            ReverbServiceProvider::class,
            ReverbManagerServiceProvider::class,
        ];
    }
}
