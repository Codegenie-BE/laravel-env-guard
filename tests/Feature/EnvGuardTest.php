<?php

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

function buildEnvGuard(string $root, array $overrides = []): EnvGuard
{
    $app = new Application($root);
    $app->instance('config', new Repository);
    $app->useEnvironmentPath($root);
    $app->loadEnvironmentFrom('.env');
    $app->useConfigPath($root.'/config');
    $app->useStoragePath($root.'/storage');
    $app->usePublicPath($root.'/public');

    $defaults = [
        'scan_paths' => [$root.'/app'],
        'project_files' => [],
        'project_directories' => [],
        'reference_files' => ['.env.example'],
        'compare_files' => ['.env.testing'],
        'discover_environment_files' => false,
        'max_file_size' => 1_048_576,
        'known_external_keys' => [],
        'ignore_keys' => [],
        'ignore_patterns' => [],
        'cache_path' => $root.'/storage/env-guard.json',
    ];

    foreach ([...$defaults, ...$overrides] as $key => $value) {
        config()->set('env-guard.'.$key, $value);
    }

    return new EnvGuard($app, new EnvironmentFileScanner, new PhpEnvironmentScanner, new TextEnvironmentScanner);
}

function findingCodesFor(array $findings, string $key): array
{
    return array_values(array_map(
        static fn (array $finding): string => $finding['code'],
        array_filter($findings, static fn (array $finding): bool => ($finding['key'] ?? null) === $key),
    ));
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/env-guard-core-'.bin2hex(random_bytes(5));

    foreach (['app', 'bootstrap/cache', 'config', 'public', 'storage'] as $directory) {
        mkdir($this->root.'/'.$directory, 0777, true);
    }
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($this->root);
});

it('audits active, reference and comparison files without leaking values', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\nLOCAL_ONLY=local-secret\nIGNORED_ONLY=ignore-secret\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\nSERVICE_TOKEN=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");
    file_put_contents($this->root.'/config/services.php', "<?php return ['token' => env('SERVICE_TOKEN')];\n");
    file_put_contents($this->root.'/app/Bad.php', "<?php env('UNDECLARED_KEY'); getenv('APP_NAME'); env(\$dynamic);\n");

    $result = buildEnvGuard($this->root, ['ignore_keys' => ['IGNORED_ONLY']])->inspect();

    expect(findingCodesFor($result['findings'], 'LOCAL_ONLY'))->toContain('missing-from-example', 'possibly-unused-key')
        ->and(findingCodesFor($result['findings'], 'IGNORED_ONLY'))->toBe([])
        ->and(findingCodesFor($result['findings'], 'SERVICE_TOKEN'))->toContain('missing-from-active', 'missing-from-environment-file')
        ->and(findingCodesFor($result['findings'], 'UNDECLARED_KEY'))->toContain('env-outside-config', 'used-but-undeclared')
        ->and(findingCodesFor($result['findings'], 'APP_NAME'))->toContain('raw-environment-access')
        ->and(array_column($result['findings'], 'code'))->toContain('dynamic-env-key')
        ->and(json_encode($result['findings']))->not->toContain('local-secret', 'ignore-secret');
});

it('discovers extra env files without treating layered files as standalone comparisons', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.production', "PROD_ONLY=one\nPROD_ONLY=two\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $result = buildEnvGuard($this->root, ['discover_environment_files' => true])->inspect();
    $productionMissing = array_filter(
        $result['findings'],
        static fn (array $finding): bool => ($finding['code'] ?? null) === 'missing-from-environment-file'
            && ($finding['path'] ?? null) === '.env.production',
    );

    expect($productionMissing)->toBe([])
        ->and(array_column($result['findings'], 'code'))->toContain('duplicate-key');
});

it('checks explicitly configured standalone environment files for completeness', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.staging', '');
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $result = buildEnvGuard($this->root, ['compare_files' => ['.env.testing', '.env.staging']])->inspect();
    $matches = array_filter(
        $result['findings'],
        static fn (array $finding): bool => ($finding['code'] ?? null) === 'missing-from-environment-file'
            && ($finding['key'] ?? null) === 'APP_NAME'
            && ($finding['path'] ?? null) === '.env.staging',
    );

    expect($matches)->toHaveCount(1);
});

it('reuses cached findings until relevant file metadata changes', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $guard = buildEnvGuard($this->root);
    $first = $guard->inspect();
    $second = $guard->inspect();

    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')]; // changed\n");
    clearstatcache();
    $third = $guard->inspect();

    expect($first['fresh'])->toBeTrue()
        ->and($second['fresh'])->toBeFalse()
        ->and($third['fresh'])->toBeTrue();
});

it('invalidates cached findings when runtime environment presence changes', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\nENV_GUARD_EXTERNAL_TOKEN=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");
    putenv('ENV_GUARD_EXTERNAL_TOKEN=runtime-secret');

    try {
        $guard = buildEnvGuard($this->root);
        $first = $guard->inspect();

        putenv('ENV_GUARD_EXTERNAL_TOKEN');
        $second = $guard->inspect();

        expect($first['fresh'])->toBeTrue()
            ->and(findingCodesFor($first['findings'], 'ENV_GUARD_EXTERNAL_TOKEN'))->not->toContain('missing-from-active')
            ->and($second['fresh'])->toBeTrue()
            ->and(findingCodesFor($second['findings'], 'ENV_GUARD_EXTERNAL_TOKEN'))->toContain('missing-from-active');
    } finally {
        putenv('ENV_GUARD_EXTERNAL_TOKEN');
    }
});

it('invalidates cached findings when behavior configuration changes', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\nCONFIG_ONLY=value\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $guard = buildEnvGuard($this->root, ['ignore_keys' => ['CONFIG_ONLY']]);
    $first = $guard->inspect();

    config()->set('env-guard.ignore_keys', []);
    $second = $guard->inspect();

    expect($first['fresh'])->toBeTrue()
        ->and(findingCodesFor($first['findings'], 'CONFIG_ONLY'))->toBe([])
        ->and($second['fresh'])->toBeTrue()
        ->and(findingCodesFor($second['findings'], 'CONFIG_ONLY'))->toContain('missing-from-example', 'possibly-unused-key');
});
