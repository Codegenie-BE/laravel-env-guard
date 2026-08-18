<?php

declare(strict_types=1);

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/** @return array{app:Application, guard:EnvGuard} */
function filesystemScenarioGuard(
    string $root,
    ?string $environmentPath = null,
    string $environmentFile = '.env',
    array $overrides = [],
): array {
    $app = new Application($root);
    $app->instance('config', new Repository);
    $app->useEnvironmentPath($environmentPath ?? $root);
    $app->loadEnvironmentFrom($environmentFile);
    $app->useConfigPath($root.'/config');
    $app->useStoragePath($root.'/storage');
    $app->usePublicPath($root.'/public');

    $defaults = [
        'scan_paths' => [$root.'/app'],
        'project_files' => [],
        'project_directories' => [],
        'reference_files' => ['.env.example'],
        'compare_files' => [],
        'discover_environment_files' => false,
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

function removeFilesystemScenarioRoot(string $root): void
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

it('uses Laravels configured environment path and active environment filename', function (): void {
    $root = sys_get_temp_dir().'/env-guard-environment-path-'.bin2hex(random_bytes(5));
    $environmentPath = $root.'/environment';

    foreach (['app', 'config', 'public', 'storage', 'environment'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($environmentPath.'/.env.local', "APP_NAME=Codegenie\n");
        file_put_contents($environmentPath.'/.env.example', "APP_NAME=\n");
        file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

        $scenario = filesystemScenarioGuard($root, $environmentPath, '.env.local');
        $result = $scenario['guard']->inspect();
        $codes = array_column($result['findings'], 'code');

        expect($scenario['app']->environmentFilePath())->toBe($environmentPath.DIRECTORY_SEPARATOR.'.env.local')
            ->and($codes)->not->toContain('active-environment-file-missing', 'missing-reference-file', 'missing-from-active');
    } finally {
        removeFilesystemScenarioRoot($root);
    }
});

it('honors the configured maximum source file size', function (): void {
    $root = sys_get_temp_dir().'/env-guard-max-size-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env', "SMALL_KEY=one\nLARGE_KEY=two\n");
        file_put_contents($root.'/.env.example', "SMALL_KEY=\nLARGE_KEY=\n");
        file_put_contents($root.'/app/Small.php', "<?php env('SMALL_KEY');\n");
        file_put_contents($root.'/app/Large.php', "<?php env('LARGE_KEY');\n".str_repeat('// padding'.PHP_EOL, 80));

        $result = filesystemScenarioGuard($root, overrides: ['max_file_size' => 128])['guard']->inspect();
        $smallCodes = array_column(array_filter(
            $result['findings'],
            static fn (array $finding): bool => ($finding['key'] ?? null) === 'SMALL_KEY',
        ), 'code');
        $largeCodes = array_column(array_filter(
            $result['findings'],
            static fn (array $finding): bool => ($finding['key'] ?? null) === 'LARGE_KEY',
        ), 'code');

        expect($smallCodes)->toContain('env-outside-config')
            ->and($smallCodes)->not->toContain('possibly-unused-key')
            ->and($largeCodes)->toContain('possibly-unused-key')
            ->and($largeCodes)->not->toContain('env-outside-config');
    } finally {
        removeFilesystemScenarioRoot($root);
    }
});

it('does not follow symlinked project source files', function (): void {
    $root = sys_get_temp_dir().'/env-guard-symlink-'.bin2hex(random_bytes(5));
    $outside = sys_get_temp_dir().'/env-guard-outside-'.bin2hex(random_bytes(5)).'.php';

    foreach (['app', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    try {
        file_put_contents($root.'/.env', "SYMLINK_KEY=value\n");
        file_put_contents($root.'/.env.example', "SYMLINK_KEY=\n");
        file_put_contents($outside, "<?php env('SYMLINK_KEY');\n");

        $link = $root.'/app/Linked.php';

        if (! function_exists('symlink') || ! @symlink($outside, $link)) {
            $this->markTestSkipped('The current platform cannot create the symlink fixture.');
        }

        $result = filesystemScenarioGuard($root)['guard']->inspect();
        $codes = array_column(array_filter(
            $result['findings'],
            static fn (array $finding): bool => ($finding['key'] ?? null) === 'SYMLINK_KEY',
        ), 'code');

        expect($codes)->toContain('possibly-unused-key')
            ->and($codes)->not->toContain('env-outside-config');
    } finally {
        @unlink($outside);
        removeFilesystemScenarioRoot($root);
    }
});
