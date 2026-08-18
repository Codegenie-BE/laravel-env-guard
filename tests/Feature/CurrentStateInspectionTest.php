<?php

declare(strict_types=1);

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/** @return array{Application, EnvGuard} */
function currentStateGuard(string $root): array
{
    $app = new class($root) extends Application
    {
        public function getCachedConfigPath()
        {
            return $this->bootstrapPath('cache/config.php');
        }
    };
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
    ] as $key => $value) {
        config()->set('env-guard.'.$key, $value);
    }

    return [
        $app,
        new EnvGuard($app, new EnvironmentFileScanner, new PhpEnvironmentScanner, new TextEnvironmentScanner),
    ];
}

it('observes current Laravel configuration-cache state without persistent scan state', function (): void {
    $root = sys_get_temp_dir().'/env-guard-current-state-'.bin2hex(random_bytes(5));

    foreach (['app', 'bootstrap/cache', 'config', 'public', 'storage'] as $directory) {
        mkdir($root.'/'.$directory, 0777, true);
    }

    file_put_contents($root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($root.'/.env.example', "APP_NAME=\n");
    file_put_contents($root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    try {
        [$uncachedApp, $uncachedGuard] = currentStateGuard($root);
        $uncached = $uncachedGuard->inspect();
        $cachedConfigPath = $uncachedApp->getCachedConfigPath();

        expect($uncachedApp->configurationIsCached())->toBeFalse()
            ->and($uncached['fresh'])->toBeTrue()
            ->and(array_column($uncached['findings'], 'code'))->not->toContain('configuration-cached');

        expect(file_put_contents($cachedConfigPath, '<?php return [];'))->not->toBeFalse();
        clearstatcache(true, $cachedConfigPath);

        [$cachedApp, $cachedGuard] = currentStateGuard($root);
        $cached = $cachedGuard->inspect();

        expect($cachedApp->configurationIsCached())->toBeTrue()
            ->and($cached['fresh'])->toBeTrue()
            ->and(array_column($cached['findings'], 'code'))->toContain('configuration-cached');

        expect(@unlink($cachedConfigPath))->toBeTrue();
        clearstatcache(true, $cachedConfigPath);

        [$clearedApp, $clearedGuard] = currentStateGuard($root);
        $cleared = $clearedGuard->inspect();

        expect($clearedApp->configurationIsCached())->toBeFalse()
            ->and($cleared['fresh'])->toBeTrue()
            ->and(array_column($cleared['findings'], 'code'))->not->toContain('configuration-cached')
            ->and(is_file($root.'/storage/framework/cache/laravel-env-guard.json'))->toBeFalse();
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
