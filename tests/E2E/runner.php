<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$laravel = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--laravel=')) {
        $laravel = substr($argument, strlen('--laravel='));
    } elseif (str_starts_with($argument, '--package-root=')) {
        $candidatePackageRoot = realpath(substr($argument, strlen('--package-root=')));

        if (! is_string($candidatePackageRoot) || ! is_file($candidatePackageRoot.DIRECTORY_SEPARATOR.'composer.json')) {
            fwrite(STDERR, "The supplied package root is invalid.\n");
            exit(2);
        }

        $packageRoot = $candidatePackageRoot;
    }
}

if (! in_array($laravel, ['12', '13'], true)) {
    fwrite(STDERR, "Usage: php tests/E2E/runner.php --laravel=12|13 [--package-root=/path/to/package]\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-env-guard-e2e-'.bin2hex(random_bytes(6));
$applicationRoot = $temporaryRoot.DIRECTORY_SEPARATOR.'application';
$syntheticSecret = 'env-guard-e2e-secret-value-do-not-log';
$optionalValue = 'env-guard-e2e-optional-value-do-not-log';

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
        $safeOutput = str_replace(
            [
                'env-guard-e2e-secret-value-do-not-log',
                'env-guard-e2e-optional-value-do-not-log',
            ],
            '[redacted]',
            $output,
        );
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

function laravelLogContents(string $applicationRoot): string
{
    $contents = '';

    foreach (glob($applicationRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
        $value = @file_get_contents($path);

        if (is_string($value)) {
            $contents .= $value;
        }
    }

    return $contents;
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

    $repositoryDefinition = json_encode([
        'type' => 'path',
        'url' => $packageRoot,
        'options' => [
            'symlink' => false,
            'versions' => [
                'codegenie-be/laravel-env-guard' => 'dev-e2e',
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    runE2eCommand([
        'composer',
        'config',
        '--json',
        'repositories.env-guard',
        $repositoryDefinition,
    ], $applicationRoot);

    runE2eCommand([
        'composer',
        'require',
        'codegenie-be/laravel-env-guard:dev-e2e',
        '--with-all-dependencies',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
    ], $applicationRoot);

    $installedPackageRoot = $applicationRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'codegenie-be'.DIRECTORY_SEPARATOR.'laravel-env-guard';

    if (! is_file($installedPackageRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'EnvGuard.php')
        || ! is_file($installedPackageRoot.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'env-guard.php')) {
        throw new RuntimeException('Composer did not install the package runtime files.');
    }

    if (is_link($installedPackageRoot)) {
        throw new RuntimeException('Composer symlinked the package instead of testing a copied install.');
    }

    $legacyGuardCache = $applicationRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'laravel-env-guard.json';
    $initial = runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (str_contains($initial['output'], 'used-but-undeclared')) {
        throw new RuntimeException('Fresh Laravel console output contains inactive framework-key noise.');
    }

    foreach (['BCRYPT_ROUNDS', 'BROADCAST_CONNECTION'] as $frameworkOwnedKey) {
        if (str_contains($initial['output'], 'possibly-unused-key] '.$frameworkOwnedKey)) {
            throw new RuntimeException($frameworkOwnedKey.' was incorrectly reported as unused framework-owned configuration.');
        }
    }

    if (is_file($legacyGuardCache)) {
        throw new RuntimeException('Laravel Env Guard created the removed persistent result cache.');
    }

    file_put_contents(
        $applicationRoot.DIRECTORY_SEPARATOR.'.env',
        PHP_EOL.'LOG_DAILY_DAYS='.$optionalValue.PHP_EOL,
        FILE_APPEND,
    );
    clearstatcache();

    $optional = runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (! str_contains($optional['output'], 'Laravel Env Guard:')
        || ! str_contains($optional['output'], 'missing-from-environment-file')
        || ! str_contains($optional['output'], 'LOG_DAILY_DAYS')) {
        throw new RuntimeException('An actively supplied optional Laravel key was not reported as environment-file drift in Artisan output.');
    }

    $logs = laravelLogContents($applicationRoot);

    if (! str_contains($logs, '[Laravel Env Guard]')
        || ! str_contains($logs, 'LOG_DAILY_DAYS')) {
        throw new RuntimeException('An optional Laravel key finding was not written to the Laravel log.');
    }

    if (str_contains($optional['output'], $optionalValue) || str_contains($logs, $optionalValue)) {
        throw new RuntimeException('An optional environment value leaked into Env Guard diagnostics.');
    }

    $environment = (string) file_get_contents($applicationRoot.DIRECTORY_SEPARATOR.'.env');
    $environment = str_replace(PHP_EOL.'LOG_DAILY_DAYS='.$optionalValue.PHP_EOL, PHP_EOL, $environment);
    file_put_contents($applicationRoot.DIRECTORY_SEPARATOR.'.env', $environment);
    clearstatcache();

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

    $uncached = runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (str_contains($uncached['output'], 'configuration-cached')) {
        throw new RuntimeException('The guard reported cached Laravel configuration before config:cache ran.');
    }

    runE2eCommand([PHP_BINARY, 'artisan', 'config:cache', '--no-ansi'], $applicationRoot);
    $cached = runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (! str_contains($cached['output'], 'configuration-cached')) {
        throw new RuntimeException('The guard did not inspect current Laravel configuration-cache state.');
    }

    runE2eCommand([PHP_BINARY, 'artisan', 'config:clear', '--no-ansi'], $applicationRoot);
    $cleared = runE2eCommand([PHP_BINARY, 'artisan', 'list', '--no-ansi'], $applicationRoot);

    if (str_contains($cleared['output'], 'configuration-cached')) {
        throw new RuntimeException('The guard did not observe the cleared Laravel configuration-cache state.');
    }

    if (is_file($legacyGuardCache)) {
        throw new RuntimeException('Laravel Env Guard persisted a result cache during E2E auditing.');
    }

    fwrite(STDOUT, 'Laravel '.$laravel." E2E scenarios passed.\n");
} finally {
    removeE2eDirectory($temporaryRoot);
}
