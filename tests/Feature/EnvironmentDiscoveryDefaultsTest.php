<?php

declare(strict_types=1);

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/** @return array{app:Application, guard:EnvGuard} */
function environmentDiscoveryGuard(string $root, string $environmentFile = '.env', array $overrides = []): array
{
    $app = new Application($root);
    $app->instance('config', new Repository);
    $app->useEnvironmentPath($root);
    $app->loadEnvironmentFrom($environmentFile);
    $app->useConfigPath($root.'/config');
    $app->useStoragePath($root.'/storage');
    $app->usePublicPath($root.'/public');

    $defaults = [
        'scan_paths' => [$root.'/app'],
        'project_files' => [],
        'project_directories' => [],
        'reference_files' => [],
        'compare_files' => [],
        'discover_environment_files' => true,
        'max_file_size' => 1_048_576,
        'known_external_keys' => [],
        'ignore_keys' => [],
        'ignore_patterns' => [],
    ];

    foreach ([...$defaults, ...$overrides] as $key => $value) {
        config()->set('env-guard.'.$key, $value);
    }

    return [
        'app' => $app,
        'guard' => new EnvGuard($app, new EnvironmentFileScanner, new PhpEnvironmentScanner, new TextEnvironmentScanner),
    ];
}

function removeEnvironmentDiscoveryRoot(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink()
            ? @rmdir($item->getPathname())
            : @unlink($item->getPathname());
    }

    @rmdir($root);
}

function environmentDiscoveryFindings(array $findings, string $code, ?string $key = null, ?string $path = null): array
{
    return array_values(array_filter(
        $findings,
        static fn (array $finding): bool => ($finding['code'] ?? null) === $code
            && ($key === null || ($finding['key'] ?? null) === $key)
            && ($path === null || ($finding['path'] ?? null) === $path),
    ));
}

it('ships name-agnostic automatic environment-file discovery by default', function (): void {
    $config = require __DIR__.'/../../config/env-guard.php';

    expect($config['reference_files'])->toBe([])
        ->and($config['compare_files'])->toBe([])
        ->and($config['discover_environment_files'])->toBeTrue();
});

it('does not require env example and compares every discovered plaintext environment file', function (): void {
    $root = sys_get_temp_dir().'/env-guard-discovery-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env', "APP_NAME=Codegenie\nLOCAL_ONLY=local\n");
        file_put_contents($root.'/.env.production', "APP_NAME=Codegenie\nPRODUCTION_ONLY=production\n");
        file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

        $result = environmentDiscoveryGuard($root)['guard']->inspect();
        $findings = $result['findings'];

        expect(environmentDiscoveryFindings($findings, 'missing-reference-file'))->toBe([])
            ->and(environmentDiscoveryFindings($findings, 'missing-from-environment-file', 'LOCAL_ONLY', '.env.production'))->toHaveCount(1)
            ->and(environmentDiscoveryFindings($findings, 'missing-from-environment-file', 'PRODUCTION_ONLY', '.env'))->toHaveCount(1);
    } finally {
        removeEnvironmentDiscoveryRoot($root);
    }
});

it('discovers renamed template files and accepts commented keys as their key inventory', function (): void {
    $root = sys_get_temp_dir().'/env-guard-renamed-template-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env', "APP_NAME=Codegenie\nSERVICE_TOKEN=secret\n");
        file_put_contents($root.'/.env.template', "APP_NAME=Laravel\n# SERVICE_TOKEN=\n");
        file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");
        file_put_contents($root.'/config/services.php', "<?php return ['token' => env('SERVICE_TOKEN')];\n");

        $result = environmentDiscoveryGuard($root)['guard']->inspect();
        $findings = $result['findings'];

        expect(environmentDiscoveryFindings($findings, 'missing-reference-file'))->toBe([])
            ->and(environmentDiscoveryFindings($findings, 'missing-from-environment-file', 'SERVICE_TOKEN', '.env.template'))->toBe([])
            ->and(environmentDiscoveryFindings($findings, 'used-but-undeclared', 'SERVICE_TOKEN'))->toBe([]);
    } finally {
        removeEnvironmentDiscoveryRoot($root);
    }
});

it('does not let a commented assignment satisfy the active Laravel environment file', function (): void {
    $root = sys_get_temp_dir().'/env-guard-active-comment-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env.local', "APP_NAME=Codegenie\n# SERVICE_TOKEN=\n");
        file_put_contents($root.'/.env.production', "APP_NAME=Codegenie\nSERVICE_TOKEN=production\n");
        file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");
        file_put_contents($root.'/config/services.php', "<?php return ['token' => env('SERVICE_TOKEN')];\n");

        $scenario = environmentDiscoveryGuard($root, '.env.local');
        $findings = $scenario['guard']->inspect()['findings'];

        expect(environmentDiscoveryFindings($findings, 'missing-from-environment-file', 'SERVICE_TOKEN', '.env.local'))->toHaveCount(1);
    } finally {
        removeEnvironmentDiscoveryRoot($root);
    }
});

it('discovers env dist while excluding encrypted and backup artifacts', function (): void {
    $root = sys_get_temp_dir().'/env-guard-discovery-exclusions-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env', "APP_NAME=Codegenie\n");
        file_put_contents($root.'/.env.dist', "APP_NAME=Laravel\nDIST_ONLY=\n");
        file_put_contents($root.'/.env.backup', "BACKUP_ONLY=one\nBACKUP_ONLY=two\n");
        file_put_contents($root.'/.env.encrypted', "ENCRYPTED_ONLY=one\nENCRYPTED_ONLY=two\n");
        file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

        $findings = environmentDiscoveryGuard($root)['guard']->inspect()['findings'];
        $paths = array_values(array_filter(array_column($findings, 'path')));

        expect(environmentDiscoveryFindings($findings, 'missing-from-environment-file', 'DIST_ONLY', '.env'))->toHaveCount(1)
            ->and($paths)->not->toContain('.env.backup', '.env.encrypted');
    } finally {
        removeEnvironmentDiscoveryRoot($root);
    }
});

it('keeps missing reference warnings only for explicitly configured references', function (): void {
    $root = sys_get_temp_dir().'/env-guard-explicit-reference-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env', "APP_NAME=Codegenie\n");
        file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

        $findings = environmentDiscoveryGuard($root, overrides: [
            'reference_files' => ['.env.custom-reference'],
        ])['guard']->inspect()['findings'];

        expect(environmentDiscoveryFindings($findings, 'missing-reference-file'))->toHaveCount(1)
            ->and(environmentDiscoveryFindings($findings, 'missing-reference-file')[0]['path'])->toBe('.env.custom-reference');
    } finally {
        removeEnvironmentDiscoveryRoot($root);
    }
});
