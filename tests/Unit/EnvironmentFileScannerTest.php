<?php

use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;

it('extracts keys without retaining values', function () {
    $path = tempnam(sys_get_temp_dir(), 'env-guard-');
    file_put_contents($path, <<<'ENV'
APP_NAME=Laravel
API_TOKEN=super-secret-value
MAIL_FROM_NAME="${APP_NAME}"
# DB_HOST=127.0.0.1
ENV);

    $result = (new EnvironmentFileScanner)->scan($path, true);

    expect(array_keys($result['keys']))->toBe(['APP_NAME', 'API_TOKEN', 'MAIL_FROM_NAME', 'DB_HOST'])
        ->and($result['keys']['DB_HOST']['commented'])->toBeTrue()
        ->and($result['interpolations'])->toContain(['key' => 'APP_NAME', 'line' => 3])
        ->and(json_encode($result))->not->toContain('super-secret-value');

    @unlink($path);
});

it('detects duplicate active keys but ignores commented documentation', function () {
    $path = tempnam(sys_get_temp_dir(), 'env-guard-');
    file_put_contents($path, "FOO=one\n# FOO=example\nFOO=two\n");

    $result = (new EnvironmentFileScanner)->scan($path, true);

    expect($result['duplicates'])->toBe([
        ['key' => 'FOO', 'lines' => [1, 3]],
    ]);

    @unlink($path);
});

it('does not treat assignments inside multiline quoted values as environment keys', function () {
    $path = tempnam(sys_get_temp_dir(), 'env-guard-');
    file_put_contents($path, <<<'ENV'
PRIVATE_KEY="-----BEGIN KEY-----
FAKE_KEY=not-an-environment-variable
-----END KEY-----"
REAL_KEY=value
SINGLE_LITERAL='${REAL_KEY}'
ESCAPED_LITERAL="\${REAL_KEY}"
EXPANDED="${REAL_KEY}"
ENV);

    $result = (new EnvironmentFileScanner)->scan($path, true);

    expect(array_keys($result['keys']))->toBe(['PRIVATE_KEY', 'REAL_KEY', 'SINGLE_LITERAL', 'ESCAPED_LITERAL', 'EXPANDED'])
        ->and(array_column($result['interpolations'], 'key'))->toBe(['REAL_KEY']);

    @unlink($path);
});
