<?php

namespace Codegenie\EnvGuard\Support;

use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Throwable;

final class LaravelOptionalEnvironmentKeyPolicy
{
    public function __construct(
        private readonly Application $app,
        private readonly EnvironmentFileScanner $environmentFiles,
    ) {}

    /** @return list<string> */
    public function inactiveKeys(): array
    {
        if (! (bool) config('env-guard.suppress_inactive_laravel_keys', true)) {
            return [];
        }

        $declared = [];
        $referencePaths = $this->referencePaths();

        foreach ($this->environmentPaths() as $path) {
            $scan = $this->environmentFiles->scan(
                $path,
                in_array($path, $referencePaths, true),
            );

            foreach (array_keys($scan['keys']) as $key) {
                $declared[$key] = true;
            }
        }

        $inactive = array_values(array_filter(
            LaravelOptionalEnvironmentKeys::all(),
            fn (string $key): bool => ! isset($declared[$key]) && ! $this->runtimeHas($key),
        ));

        sort($inactive);

        return $inactive;
    }

    /** @return list<string> */
    private function environmentPaths(): array
    {
        $paths = [
            $this->app->environmentFilePath(),
            ...$this->referencePaths(),
        ];

        foreach ((array) config('env-guard.compare_files', []) as $file) {
            if (is_string($file) && $file !== '') {
                $paths[] = $this->resolveEnvironmentPath($file);
            }
        }

        $environmentPath = $this->app->environmentPath();

        if ((bool) config('env-guard.discover_environment_files', false) && is_dir($environmentPath)) {
            foreach (glob(rtrim($environmentPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.env.*') ?: [] as $path) {
                if (! $this->excludedEnvironmentFile(basename($path))) {
                    $paths[] = $path;
                }
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function referencePaths(): array
    {
        $paths = [];

        foreach ((array) config('env-guard.reference_files', ['.env.example']) as $file) {
            if (is_string($file) && $file !== '') {
                $paths[] = $this->resolveEnvironmentPath($file);
            }
        }

        return array_values(array_unique($paths));
    }

    private function resolveEnvironmentPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($this->app->environmentPath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    private function excludedEnvironmentFile(string $name): bool
    {
        if ($name === '.env.example' || str_ends_with($name, '.encrypted')) {
            return true;
        }

        foreach (['.bak', '.backup', '.old', '.dist'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function runtimeHas(string $key): bool
    {
        try {
            return Env::get($key) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
