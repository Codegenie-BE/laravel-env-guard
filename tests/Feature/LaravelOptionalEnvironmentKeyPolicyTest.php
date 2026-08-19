<?php

use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Support\LaravelOptionalEnvironmentKeyPolicy;
use Illuminate\Foundation\Application;

it('suppresses only absent Laravel optional keys and keeps documented optional keys auditable', function () {
    $root = sys_get_temp_dir().'/env-guard-optional-policy-'.bin2hex(random_bytes(5));
    mkdir($root, 0777, true);

    file_put_contents($root.'/.env', "MAIL_URL=smtp://localhost\n# REDIS_URL=redis://commented\n");
    file_put_contents($root.'/.env.example', "APP_NAME=Laravel\n# DB_HOST=127.0.0.1\n");

    /** @var Application $app */
    $app = $this->app;
    $originalEnvironmentPath = $app->environmentPath();
    $originalEnvironmentFile = basename($app->environmentFilePath());

    $app->useEnvironmentPath($root);
    $app->loadEnvironmentFrom('.env');

    config()->set('env-guard.suppress_inactive_laravel_keys', true);
    config()->set('env-guard.reference_files', ['.env.example']);
    config()->set('env-guard.compare_files', []);
    config()->set('env-guard.discover_environment_files', false);

    try {
        $inactive = (new LaravelOptionalEnvironmentKeyPolicy(
            $app,
            new EnvironmentFileScanner,
        ))->inactiveKeys();

        expect($inactive)
            ->toContain('REDIS_URL', 'DB_QUEUE_CONNECTION', 'SESSION_CONNECTION')
            ->not->toContain('MAIL_URL', 'DB_HOST');
    } finally {
        $app->useEnvironmentPath($originalEnvironmentPath);
        $app->loadEnvironmentFrom($originalEnvironmentFile);
        @unlink($root.'/.env');
        @unlink($root.'/.env.example');
        @rmdir($root);
    }
});

it('keeps optional keys in automatically discovered renamed env peers auditable', function () {
    $root = sys_get_temp_dir().'/env-guard-optional-discovery-'.bin2hex(random_bytes(5));
    mkdir($root, 0777, true);

    file_put_contents($root.'/.env', "APP_NAME=Laravel\n");
    file_put_contents($root.'/.env.template', "MAIL_URL=smtp://template\n");
    file_put_contents($root.'/.env.dist', "REDIS_URL=redis://dist\n");
    file_put_contents($root.'/.env.backup', "SESSION_CONNECTION=mysql\n");
    file_put_contents($root.'/.env.encrypted', "DB_QUEUE_CONNECTION=database\n");

    /** @var Application $app */
    $app = $this->app;
    $originalEnvironmentPath = $app->environmentPath();
    $originalEnvironmentFile = basename($app->environmentFilePath());

    $app->useEnvironmentPath($root);
    $app->loadEnvironmentFrom('.env');

    config()->set('env-guard.suppress_inactive_laravel_keys', true);
    config()->set('env-guard.reference_files', []);
    config()->set('env-guard.compare_files', []);
    config()->set('env-guard.discover_environment_files', true);

    try {
        $inactive = (new LaravelOptionalEnvironmentKeyPolicy(
            $app,
            new EnvironmentFileScanner,
        ))->inactiveKeys();

        expect($inactive)
            ->not->toContain('MAIL_URL', 'REDIS_URL')
            ->toContain('SESSION_CONNECTION', 'DB_QUEUE_CONNECTION');
    } finally {
        $app->useEnvironmentPath($originalEnvironmentPath);
        $app->loadEnvironmentFrom($originalEnvironmentFile);

        foreach (['.env', '.env.template', '.env.dist', '.env.backup', '.env.encrypted'] as $file) {
            @unlink($root.'/'.$file);
        }

        @rmdir($root);
    }
});

it('can disable Laravel optional key suppression for strict projects', function () {
    config()->set('env-guard.suppress_inactive_laravel_keys', false);

    $policy = app(LaravelOptionalEnvironmentKeyPolicy::class);

    expect($policy->inactiveKeys())->toBe([]);
});
