<?php

use Codegenie\EnvGuard\ConsoleReporter;

it('renders warning and error findings for Artisan without exposing values', function () {
    $output = (new ConsoleReporter)->render([
        [
            'severity' => 'warning',
            'code' => 'missing-from-example',
            'key' => 'OPTIONAL_KEY',
            'message' => 'Environment key OPTIONAL_KEY is not documented.',
            'path' => '.env',
            'line' => 12,
        ],
        [
            'severity' => 'error',
            'code' => 'env-outside-config',
            'key' => 'UNSAFE_KEY',
            'message' => 'env(UNSAFE_KEY) is used outside Laravel configuration.',
            'path' => 'app/Example.php',
            'line' => 8,
        ],
    ]);

    expect($output)
        ->toContain('Laravel Env Guard: 1 warning(s), 1 error(s)')
        ->toContain('WARNING [missing-from-example] OPTIONAL_KEY')
        ->toContain('.env:12')
        ->toContain('ERROR [env-outside-config] UNSAFE_KEY')
        ->toContain('app/Example.php:8')
        ->not->toContain('super-secret-value');
});

it('writes nothing when there are no findings', function () {
    $stream = fopen('php://temp', 'w+');

    expect($stream)->not->toBeFalse();

    (new ConsoleReporter)->report([], $stream);
    rewind($stream);

    expect(stream_get_contents($stream))->toBe('');

    fclose($stream);
});
