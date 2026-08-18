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
REFERENCE=${REAL_KEY}
-----END KEY-----"
# COMMENTED_BLOCK="documentation
# FAKE_COMMENTED_KEY=not-a-key
# end"
REAL_KEY=value
SINGLE_LITERAL='${REAL_KEY}'
ESCAPED_LITERAL="\${REAL_KEY}"
DOUBLE_BACKSLASH="\\${REAL_KEY}"
EXPANDED="${REAL_KEY}"
SINGLE_MULTILINE='escaped \' quote
FAKE_SINGLE_KEY=not-an-environment-variable
end'
ENV);

    $result = (new EnvironmentFileScanner)->scan($path, true);

    expect(array_keys($result['keys']))->toBe(['PRIVATE_KEY', 'COMMENTED_BLOCK', 'REAL_KEY', 'SINGLE_LITERAL', 'ESCAPED_LITERAL', 'DOUBLE_BACKSLASH', 'EXPANDED', 'SINGLE_MULTILINE'])
        ->and(array_column($result['interpolations'], 'key'))->toBe(['REAL_KEY', 'REAL_KEY', 'REAL_KEY']);

    @unlink($path);
});

it('matches phpdotenv variable-name rules including quoted and numeric names', function () {
    $path = tempnam(sys_get_temp_dir(), 'env-guard-');
    file_put_contents($path, <<<'ENV'
1ST_KEY=value
"QUOTED.KEY"=value
UNICØDE=value
INVALID-KEY=value
ENV);

    $result = (new EnvironmentFileScanner)->scan($path);

    expect(array_keys($result['keys']))->toBe(['1ST_KEY', 'QUOTED.KEY', 'UNICØDE']);

    @unlink($path);
});

it('ignores interpolations in dotenv comments while preserving quoted content', function () {
    $path = tempnam(sys_get_temp_dir(), 'env-guard-');
    file_put_contents($path, <<<'ENV'
BASE=value
UNQUOTED=${BASE}#${UNQUOTED_COMMENT}
SPACED=${BASE} # ${SPACED_COMMENT}
QUOTED="${BASE} # ${INSIDE_QUOTE}" # ${QUOTED_COMMENT}
MULTILINE="first ${BASE}
second # ${INSIDE_MULTILINE}
end" # ${CLOSING_COMMENT}
SINGLE='${SINGLE_LITERAL}'
ENV);

    $result = (new EnvironmentFileScanner)->scan($path);
    $keys = array_column($result['interpolations'], 'key');

    expect($keys)->toBe(['BASE', 'BASE', 'BASE', 'INSIDE_QUOTE', 'BASE', 'INSIDE_MULTILINE'])
        ->and($keys)->not->toContain(
            'UNQUOTED_COMMENT',
            'SPACED_COMMENT',
            'QUOTED_COMMENT',
            'CLOSING_COMMENT',
            'SINGLE_LITERAL',
        );

    @unlink($path);
});
