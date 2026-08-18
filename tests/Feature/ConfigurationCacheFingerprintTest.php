<?php

declare(strict_types=1);

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

function configurationCacheGuard(string $root): array
{
    $app = new Application($root);
    $app->instance('config', new Repository);
    $app->useEnvironmentPath($root);
    $app->loadEnvironmentFrom('.env');
    $app->useConfigPath($root.'/config');
    $app->useStoragePath($root.'/storage');
    $app->usePublicPath($root.'/public');

    foreach ([
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
        'cache_path' => $root.'/storage/env-guard.json',
    ] as $key => $value) {
        config()->set('env-guard.'.$key, $value);
    }

    return [
        $app,
        new EnvGuard($app, new EnvironmentFileScanner, new PhpEnvironmentScanner, new TextEnvironmentScanner),
    ];
}

it('invalidates cached findings when Laravel configuration cache state changes', function () {
    $root = sys_get_temp_dir().'/env-guard-config-cache-'.bin2hex(random_bytes(5));

    foreach (['app', 'bootstrap/cache', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    file_put_contents($root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($root.'/.env.example', "APP_NAME=\n");
    file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    [$app, $guard] = configurationCacheGuard($root);

    try {
        $uncached = $guard->inspect();
        $cachedConfigPath = $app->getCachedConfigPath();
        file_put_contents($cachedConfigPath, '<?php return [];');
        clearstatcache();

        $cached = $guard->inspect();

        @unlink($cachedConfigPath);
        clearstatcache();

        $cleared = $guard->inspect();

        expect($uncached['fresh'])->toBeTrue()
            ->and(array_column($uncached['findings'], 'code'))->not->toContain('configuration-cached')
            ->and($cached['fresh'])->toBeTrue()
            ->and(array_column($cached['findings'], 'code'))->toContain('configuration-cached')
            ->and($cleared['fresh'])->toBeTrue()
            ->and(array_column($cleared['findings'], 'code'))->not->toContain('configuration-cached');
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($root);
    }
});
