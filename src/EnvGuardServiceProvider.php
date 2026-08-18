<?php

namespace Codegenie\EnvGuard;

use Codegenie\EnvGuard\Exceptions\EnvironmentGuardException;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Codegenie\EnvGuard\Support\LaravelOptionalEnvironmentKeyPolicy;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class EnvGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/env-guard.php', 'env-guard');

        $this->app->singleton(EnvironmentFileScanner::class);
        $this->app->singleton(PhpEnvironmentScanner::class);
        $this->app->singleton(TextEnvironmentScanner::class);
        $this->app->singleton(ConsoleReporter::class);
        $this->app->singleton(
            LaravelOptionalEnvironmentKeyPolicy::class,
            function ($app): LaravelOptionalEnvironmentKeyPolicy {
                /** @var Application $app */
                return new LaravelOptionalEnvironmentKeyPolicy(
                    $app,
                    $app->make(EnvironmentFileScanner::class),
                );
            },
        );

        $this->app->singleton(EnvGuard::class, function ($app): EnvGuard {
            /** @var Application $app */
            return new EnvGuard(
                $app,
                $app->make(EnvironmentFileScanner::class),
                $app->make(PhpEnvironmentScanner::class),
                $app->make(TextEnvironmentScanner::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/env-guard.php' => config_path('env-guard.php'),
        ], 'env-guard-config');

        if (! (bool) config('env-guard.enabled', true)) {
            return;
        }

        $environments = array_values(array_filter(
            (array) config('env-guard.environments', ['local']),
            'is_string',
        ));

        if ($environments === [] || ! $this->app->environment($environments)) {
            return;
        }

        $this->applyInactiveLaravelOptionalKeys();

        try {
            $result = $this->app->make(EnvGuard::class)->inspect();
        } catch (Throwable $exception) {
            $this->log('warning', 'Laravel Env Guard could not complete its development audit.', [
                'exception' => $exception::class,
            ]);

            return;
        }

        if ($result['fresh']) {
            foreach ($result['findings'] as $finding) {
                $this->log($finding['severity'] === 'error' ? 'error' : 'warning', $finding['message'], [
                    'code' => $finding['code'] ?? null,
                    'key' => $finding['key'] ?? null,
                    'path' => $finding['path'] ?? null,
                    'line' => $finding['line'] ?? null,
                ]);
            }
        }

        if ($this->app->runningInConsole() && (bool) config('env-guard.console_output', true)) {
            /** @var ConsoleReporter $reporter */
            $reporter = $this->app->make(ConsoleReporter::class);
            $reporter->report($result['findings']);
        }

        if ((bool) config('env-guard.fail_on_error', true) && $this->hasErrors($result['findings'])) {
            throw EnvironmentGuardException::fromFindings($result['findings']);
        }
    }

    private function applyInactiveLaravelOptionalKeys(): void
    {
        if (! (bool) config('env-guard.suppress_inactive_laravel_keys', true)) {
            return;
        }

        $configured = array_values(array_filter(
            (array) config('env-guard.ignore_keys', []),
            'is_string',
        ));
        /** @var LaravelOptionalEnvironmentKeyPolicy $policy */
        $policy = $this->app->make(LaravelOptionalEnvironmentKeyPolicy::class);
        $inactive = $policy->inactiveKeys();

        config([
            'env-guard.ignore_keys' => array_values(array_unique([
                ...$configured,
                ...$inactive,
            ])),
        ]);
    }

    /** @param list<array<string, mixed>> $findings */
    private function hasErrors(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? null) === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $logger = $this->app->make('log');
            $logger->{$level}('[Laravel Env Guard] '.$message, array_filter(
                $context,
                static fn (mixed $value): bool => $value !== null,
            ));
        } catch (Throwable) {
            // Logging must never make a development audit fatal.
        }
    }
}
