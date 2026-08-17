<?php

use Codegenie\EnvGuard\EnvGuard;

it('registers the guard through package discovery compatible service provider bootstrapping', function () {
    expect(app()->bound(EnvGuard::class))->toBeTrue();
});

it('is disabled automatically outside configured development environments', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('env-guard.environments'))->toBe(['local']);
});
