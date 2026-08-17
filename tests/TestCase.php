<?php

namespace Codegenie\EnvGuard\Tests;

use Codegenie\EnvGuard\EnvGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            EnvGuardServiceProvider::class,
        ];
    }
}
