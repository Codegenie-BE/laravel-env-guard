<?php

use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Support\LaravelOptionalEnvironmentKeyPolicy;
use Illuminate\Foundation\Application;

it('suppresses only absent Laravel optional keys and keeps declared optional keys auditable', function () {
    $root = sys_get_temp_dir().'/env-guard-optional-policy-'.bin2hex(random_bytes(5));
    mkdir($root, 0777, true);

    file_put_contents($root.'/.env', "MAIL_URL=smtp://localhost\n# REDIS_URL=redis://commented\n");
    file_put_contents($root.'/.env.example', "APP_NAME=Laravel\n");

    $app = new Application($root);
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
            ->not->toContain('MAIL_URL');
    } finally {
        @unlink($root.'/.env');
        @unlink($root.'/.env.example');
        @rmdir($root);
    }
});

it('can disable Laravel optional key suppression for strict projects', function () {
    config()->set('env-guard.suppress_inactive_laravel_keys', false);

    $policy = app(LaravelOptionalEnvironmentKeyPolicy::class);

    expect($policy->inactiveKeys())->toBe([]);
});
