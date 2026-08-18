<?php

declare(strict_types=1);

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('removes legacy default and custom result caches without creating a new cache', function (): void {
    $root = sys_get_temp_dir().'/env-guard-legacy-cache-'.bin2hex(random_bytes(5));

    foreach (['app', 'config', 'public', 'storage/framework/cache', 'storage/legacy'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    file_put_contents($root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($root.'/.env.example', "APP_NAME=\n");
    file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $defaultCache = $root.'/storage/framework/cache/laravel-env-guard.json';
    $customCache = $root.'/storage/legacy/env-guard.json';
    file_put_contents($defaultCache, '{"legacy":true}');
    file_put_contents($customCache, '{"legacy":true}');

    try {
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
            'cache_path' => 'storage/legacy/env-guard.json',
        ] as $key => $value) {
            config()->set('env-guard.'.$key, $value);
        }

        $guard = new EnvGuard(
            $app,
            new EnvironmentFileScanner,
            new PhpEnvironmentScanner,
            new TextEnvironmentScanner,
        );

        $first = $guard->inspect();
        $second = $guard->inspect();

        expect($first['fresh'])->toBeTrue()
            ->and($second['fresh'])->toBeTrue()
            ->and(is_file($defaultCache))->toBeFalse()
            ->and(is_file($customCache))->toBeFalse();
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
