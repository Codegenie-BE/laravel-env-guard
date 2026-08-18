<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$laravel = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--laravel=')) {
        $laravel = substr($argument, strlen('--laravel='));
    }
}

if (! in_array($laravel, ['12', '13'], true)) {
    fwrite(STDERR, "Usage: php tests/E2E/runner.php --laravel=12|13\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-env-guard-e2e-'.bin2hex(random_bytes(6));
$applicationRoot = $temporaryRoot.DIRECTORY_SEPARATOR.'application';
$syntheticSecret = 'env-guard-e2e-secret-value-do-not-log';

/**
 * @param  list<string>  $command
 * @return array{exit_code:int, output:string}
 */
function runE2eCommand(array $command, string $workingDirectory, bool $mustSucceed = true): array
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start E2E command.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $output = ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr);

    if ($mustSucceed && $exitCode !== 0) {
        $safeOutput = str_replace('env-guard-e2e-secret-value-do-not-log', '[redacted]', $output);
        $safeOutput = substr($safeOutput, 0, 6000);

        throw new RuntimeException(sprintf(
            "E2E command failed with exit code %d: %s\n%s",
            $exitCode,
            implode(' ', $command),
            $safeOutput,
        ));
    }

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function removeE2eDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } else {
            @rmdir($item->getPathname());
        }
    }

    @rmdir($directory);
}

/** @return list<string> */
function cachedFindingCodes(string $cachePath): array
{
    if (! is_file($cachePath)) {
        throw new RuntimeException('Laravel Env Guard did not create its metadata cache.');
    }

    $payload = json_decode((string) file_get_contents($cachePath), true, 512, JSON_THROW_ON_ERROR);
    $findings = $payload['findings'] ?? null;

    if (! is_array($findings)) {
        throw new RuntimeException('Laravel Env Guard metadata cache has no findings array.');
    }

    return array_values(array_filter(array_map(
        static fn (mixed $finding): ?string => is_array($finding) && is_string($finding['code'] ?? null)
            ? $finding['code']
            : null,
        $findings,
    )));
}

try {
    if (! @mkdir($temporaryRoot, 0700, true) && ! is_dir($temporaryRoot)) {
        throw new RuntimeException('Unable to create the E2E temporary directory.');
    }

    runE2eCommand([
        'composer',
        'create-project',
        'laravel/laravel',
        $applicationRoot,
        '^'.$laravel.'.0',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
    ], $temporaryRoot);

    runE2eCommand([
        'composer',
        'config',
        'repositories.env-guard',
        'path',
        $packageRoot,
    ], $applicationRoot);

    runE2eCommand([
        'composer',
        'require',
        'codegenie-be/laravel-env-guard:@dev',
        '--with-all-dependencies',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
    ], $applicationRoot);

    runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    file_put_contents(
        $applicationRoot.DIRECTORY_SEPARATOR.'.env',
        PHP_EOL.'ENV_GUARD_E2E_SECRET='.$syntheticSecret.PHP_EOL,
        FILE_APPEND,
    );
    file_put_contents(
        $applicationRoot.DIRECTORY_SEPARATOR.'.env.example',
        PHP_EOL.'ENV_GUARD_E2E_SECRET='.PHP_EOL,
        FILE_APPEND,
    );
    file_put_contents(
        $applicationRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'EnvGuardProbe.php',
        "<?php\n\nenv('ENV_GUARD_E2E_SECRET');\n",
    );
    clearstatcache();

    $unsafe = runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot, false);

    if ($unsafe['exit_code'] === 0) {
        throw new RuntimeException('Unsafe env() usage outside config did not block Laravel boot.');
    }

    if (! str_contains($unsafe['output'], 'env-outside-config')
        || ! str_contains($unsafe['output'], 'ENV_GUARD_E2E_SECRET')) {
        throw new RuntimeException('Unsafe env() usage did not produce the expected key-only diagnostic.');
    }

    if (str_contains($unsafe['output'], $syntheticSecret)) {
        throw new RuntimeException('A synthetic environment value leaked into Laravel Env Guard output.');
    }

    file_put_contents(
        $applicationRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'EnvGuardProbe.php',
        "<?php\n\nconfig('app.name');\n",
    );
    clearstatcache();

    runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    $guardCache = $applicationRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'laravel-env-guard.json';

    if (in_array('configuration-cached', cachedFindingCodes($guardCache), true)) {
        throw new RuntimeException('The guard reported cached Laravel configuration before config:cache ran.');
    }

    runE2eCommand([PHP_BINARY, 'artisan', 'config:cache', '--no-ansi'], $applicationRoot);
    runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (! in_array('configuration-cached', cachedFindingCodes($guardCache), true)) {
        throw new RuntimeException('The guard cache did not invalidate when Laravel configuration became cached.');
    }

    runE2eCommand([PHP_BINARY, 'artisan', 'config:clear', '--no-ansi'], $applicationRoot);
    runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (in_array('configuration-cached', cachedFindingCodes($guardCache), true)) {
        throw new RuntimeException('The guard cache did not invalidate when Laravel configuration became uncached.');
    }

    fwrite(STDOUT, 'Laravel '.$laravel." E2E scenarios passed.\n");
} finally {
    removeE2eDirectory($temporaryRoot);
}
