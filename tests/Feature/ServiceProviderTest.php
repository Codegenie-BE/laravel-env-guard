<?php

use Codegenie\EnvGuard\EnvGuard;

it('registers the guard through package discovery compatible service provider bootstrapping', function () {
    expect(app()->bound(EnvGuard::class))->toBeTrue();
});

it('is disabled automatically outside configured development environments', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('env-guard.environments'))->toBe(['local']);
});

it('defaults automatic full-project audits to console boots', function () {
    $provider = file_get_contents(__DIR__.'/../../src/EnvGuardServiceProvider.php');

    expect(config('env-guard.console_only'))->toBeTrue()
        ->and($provider)->toContain("config('env-guard.console_only', true)")
        ->and($provider)->toContain('! $this->app->runningInConsole()');
});
