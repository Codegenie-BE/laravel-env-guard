<?php

use Codegenie\EnvGuard\Exceptions\EnvironmentGuardException;

it('formats only blocking findings with useful location and fallback details', function () {
    $exception = EnvironmentGuardException::fromFindings([
        [
            'severity' => 'error',
            'code' => 'env-outside-config',
            'message' => 'Move the key into config.',
            'path' => 'app/Bad.php',
            'line' => 17,
        ],
        [
            'severity' => 'error',
            'code' => 'missing-key',
            'message' => 'The key is missing.',
            'path' => '.env.example',
        ],
        [
            'severity' => 'error',
        ],
        [
            'severity' => 'warning',
            'code' => 'possibly-unused-key',
            'message' => 'warning-marker',
        ],
    ]);

    expect($exception->getMessage())
        ->toContain('Laravel Env Guard found 3 blocking issue(s):')
        ->toContain('[env-outside-config] Move the key into config. at app/Bad.php:17')
        ->toContain('[missing-key] The key is missing. at .env.example')
        ->toContain('[error] Environment configuration error.')
        ->not->toContain('warning-marker');
});
