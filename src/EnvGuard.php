<?php

namespace Codegenie\EnvGuard;

use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class EnvGuard
{
    public function __construct(
        private readonly Application $app,
        private readonly EnvironmentFileScanner $environmentFiles,
        private readonly PhpEnvironmentScanner $php,
        private readonly TextEnvironmentScanner $text,
    ) {}

    /**
     * Inspect the current project state from disk.
     *
     * The legacy fresh/fingerprint fields remain for 1.x API compatibility,
     * but no persistent result cache is read or written.
     *
     * @return array{findings:list<array<string, mixed>>, fresh:bool, fingerprint:string}
     */
    public function inspect(): array
    {
        $this->removeLegacyResultCache();
        $findings = $this->scan($this->collectSourceFiles(), $this->environmentPaths());

        return [
            'findings' => $findings,
            'fresh' => true,
            'fingerprint' => hash('sha256', serialize($findings)),
        ];
    }

    /**
     * @param  list<string>  $sourceFiles
     * @param  list<string>  $environmentPaths
     * @return list<array<string, mixed>>
     */
    private function scan(array $sourceFiles, array $environmentPaths): array
    {
        $findings = [];
        $environmentScans = [];
        $activePath = $this->app->environmentFilePath();
        $referencePaths = $this->referencePaths();

        foreach ((array) config('env-guard.ignore_patterns', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '' || @preg_match($pattern, '') === false) {
                $findings[] = $this->finding(
                    'warning',
                    'invalid-ignore-pattern',
                    null,
                    'An env-guard.ignore_patterns entry is not a valid regular expression and was ignored.',
                    null,
                    null,
                );
            }
        }

        if ($this->app->configurationIsCached()) {
            $findings[] = $this->finding(
                'warning',
                'configuration-cached',
                null,
                'Laravel configuration is cached in a guarded development environment. Laravel recommends not caching configuration during local development.',
                $this->app->getCachedConfigPath(),
                null,
            );
        }

        foreach ($environmentPaths as $path) {
            // Parse commented assignments for every discovered environment file so
            // renamed templates retain their key inventory without relying on a
            // special ".env.example" filename. Commented assignments never count
            // as active values in Laravel's currently active environment file.
            $environmentScans[$path] = $this->environmentFiles->scan($path, true);
        }

        $declared = [];
        $activeDeclared = [];

        foreach ($environmentScans as $scan) {
            foreach ($scan['keys'] as $key => $meta) {
                if ($meta['commented'] && $scan['path'] === $activePath) {
                    continue;
                }

                $declared[$key] ??= [];
                $declared[$key][] = [
                    'path' => $scan['path'],
                    'line' => $meta['line'],
                    'commented' => $meta['commented'],
                ];

                if (! $meta['commented']) {
                    $activeDeclared[$key] = true;
                }
            }

            foreach ($scan['duplicates'] as $duplicate) {
                $findings[] = $this->finding(
                    'error',
                    'duplicate-key',
                    $duplicate['key'],
                    sprintf('Environment key %s is defined more than once on lines %s.', $duplicate['key'], implode(', ', $duplicate['lines'])),
                    $scan['path'],
                    $duplicate['lines'][0] ?? null,
                );
            }
        }

        $declaredByCase = [];

        foreach ($declared as $key => $locations) {
            $declaredByCase[strtolower($key)][$key] = $locations;
        }

        foreach ($declaredByCase as $variants) {
            if (count($variants) < 2) {
                continue;
            }

            $keys = array_keys($variants);
            $secondLocations = $variants[$keys[1]] ?? [];
            $location = $secondLocations[0] ?? [];
            $findings[] = $this->finding(
                'error',
                'case-mismatch',
                $keys[1],
                sprintf('Environment files declare the same key with different casing: %s.', implode(' / ', $keys)),
                $location['path'] ?? null,
                $location['line'] ?? null,
            );
        }

        $phpFiles = array_values(array_filter(
            $sourceFiles,
            static fn (string $file): bool => str_ends_with($file, '.php') && ! str_ends_with($file, '.blade.php'),
        ));

        $phpScan = $this->php->scan($phpFiles, $this->app->configPath());
        $textScan = $this->text->scan($sourceFiles, array_keys($declared));
        $usages = [];

        foreach ($phpScan['usages'] as $usage) {
            $usages[$usage['key']][] = $usage;

            if (! $usage['in_config']) {
                $findings[] = $this->finding(
                    'error',
                    'env-outside-config',
                    $usage['key'],
                    sprintf('%s(%s) is used outside Laravel configuration. Read it in config/*.php and use config() elsewhere.', $usage['source'], $usage['key']),
                    $usage['path'],
                    $usage['line'],
                );
            }
        }

        foreach ($phpScan['dynamic'] as $usage) {
            $findings[] = $this->finding(
                $usage['in_config'] ? 'warning' : 'error',
                'dynamic-env-key',
                null,
                $usage['in_config']
                    ? 'A dynamic env() key cannot be audited statically. Prefer a literal environment key when practical.'
                    : 'A dynamic env() call is used outside config/*.php and is unsafe with Laravel configuration caching.',
                $usage['path'],
                $usage['line'],
            );
        }

        foreach ($textScan['blade_env'] as $usage) {
            $usages[$usage['key']][] = [
                ...$usage,
                'source' => 'blade-env',
                'in_config' => false,
            ];

            $findings[] = $this->finding(
                'error',
                'env-outside-config',
                $usage['key'],
                sprintf('env(%s) is used in a Blade view. Read it in config/*.php and use config() in the view.', $usage['key']),
                $usage['path'],
                $usage['line'],
            );
        }

        foreach ($textScan['usages'] as $usage) {
            $usages[$usage['key']][] = $usage;
        }

        foreach ($environmentScans as $scan) {
            foreach ($scan['interpolations'] as $usage) {
                $usages[$usage['key']][] = [
                    'key' => $usage['key'],
                    'path' => $scan['path'],
                    'line' => $usage['line'],
                    'source' => 'dotenv-interpolation',
                ];
            }
        }

        $declaredCaseInsensitive = [];

        foreach (array_keys($declared) as $key) {
            $declaredCaseInsensitive[strtolower($key)][] = $key;
        }

        foreach ($phpScan['raw'] as $usage) {
            $caseMatches = $declaredCaseInsensitive[strtolower($usage['key'])] ?? [];

            if (! isset($declared[$usage['key']]) && $caseMatches === []) {
                continue;
            }

            $usages[$usage['key']][] = $usage;
            $findings[] = $this->finding(
                'error',
                'raw-environment-access',
                $usage['key'],
                sprintf('%s directly reads project environment key %s. Use env() in config/*.php and config() elsewhere.', $usage['source'], $usage['key']),
                $usage['path'],
                $usage['line'],
            );
        }

        foreach ($usages as $key => $locations) {
            if (isset($declared[$key]) || $this->isIgnored($key)) {
                continue;
            }

            $caseMatches = $declaredCaseInsensitive[strtolower($key)] ?? [];
            $first = $locations[0];

            if ($caseMatches !== []) {
                $findings[] = $this->finding(
                    'error',
                    'case-mismatch',
                    $key,
                    sprintf('Environment key %s differs in case from declared key %s.', $key, implode(' / ', $caseMatches)),
                    $first['path'],
                    $first['line'],
                );

                continue;
            }

            $findings[] = $this->finding(
                'warning',
                'used-but-undeclared',
                $key,
                sprintf('Environment key %s is referenced by the project but is not declared in any scanned environment file.', $key),
                $first['path'],
                $first['line'],
            );
        }

        $activeScan = $environmentScans[$activePath] ?? null;
        $referenceScans = array_values(array_filter(
            $environmentScans,
            static fn (array $scan): bool => in_array($scan['path'], $referencePaths, true),
        ));
        $documentedKeys = [];

        foreach ($referenceScans as $scan) {
            if (! $scan['exists']) {
                $findings[] = $this->finding(
                    'warning',
                    'missing-reference-file',
                    null,
                    sprintf('Explicitly configured reference environment file %s does not exist.', basename($scan['path'])),
                    $scan['path'],
                    null,
                );
            }

            foreach (array_keys($scan['keys']) as $key) {
                $documentedKeys[$key] = true;
            }
        }

        $existingReferenceScans = array_values(array_filter(
            $referenceScans,
            static fn (array $scan): bool => $scan['exists'],
        ));
        $referenceLabel = count($existingReferenceScans) === 1
            ? basename($existingReferenceScans[0]['path'])
            : 'the configured reference environment files';
        $projectKeys = array_fill_keys(array_keys($usages), true);

        if (! is_array($activeScan) || ! $activeScan['exists']) {
            $findings[] = $this->finding(
                'warning',
                'active-environment-file-missing',
                null,
                sprintf("Laravel's active environment file %s does not exist; values may be coming exclusively from external environment variables.", basename($activePath)),
                $activePath,
                null,
            );
        }

        if ($existingReferenceScans !== [] && is_array($activeScan) && $activeScan['exists']) {
            foreach ($activeScan['keys'] as $key => $meta) {
                if ($meta['commented'] || $this->isIgnored($key) || isset($documentedKeys[$key])) {
                    continue;
                }

                $findings[] = $this->finding(
                    'warning',
                    'missing-from-example',
                    $key,
                    sprintf('Environment key %s exists in the active environment file but is not documented in %s.', $key, $referenceLabel),
                    $activePath,
                    $meta['line'],
                );
            }
        }

        if ($existingReferenceScans !== []) {
            foreach (array_keys($projectKeys) as $key) {
                if (isset($documentedKeys[$key]) || $this->isIgnored($key) || ! isset($declared[$key])) {
                    continue;
                }

                $activeMeta = is_array($activeScan) ? ($activeScan['keys'][$key] ?? null) : null;

                if (is_array($activeMeta) && ! $activeMeta['commented']) {
                    continue;
                }

                $first = $usages[$key][0] ?? null;

                if (! is_array($first)) {
                    continue;
                }

                $findings[] = $this->finding(
                    'warning',
                    'missing-from-reference-file',
                    $key,
                    sprintf('Environment key %s is used by the project but is not documented in %s.', $key, $referenceLabel),
                    $first['path'],
                    $first['line'],
                );
            }
        }

        foreach ($referenceScans as $scan) {
            if (! $scan['exists'] || ! is_array($activeScan) || ! $activeScan['exists']) {
                continue;
            }

            foreach ($scan['keys'] as $key => $meta) {
                $activeMeta = $activeScan['keys'][$key] ?? null;

                if ($meta['commented']
                    || (is_array($activeMeta) && ! $activeMeta['commented'])
                    || $this->runtimeHas($key)) {
                    continue;
                }

                $findings[] = $this->finding(
                    'warning',
                    'missing-from-active',
                    $key,
                    sprintf('Environment key %s is active in %s but missing from the active environment file.', $key, basename($scan['path'])),
                    $activePath,
                    null,
                );
            }
        }

        $phpUnitKeys = array_fill_keys($textScan['phpunit_keys'], true);

        // Default mode is name-agnostic peer comparison: every discovered
        // plaintext .env / .env.* file participates. Projects that explicitly
        // configure reference_files keep the previous reference-vs-active
        // semantics and only compare files explicitly listed in compare_files.
        $comparisonPaths = $referencePaths === []
            ? array_values(array_unique([
                $activePath,
                ...$this->comparisonPaths(),
                ...$this->discoveredEnvironmentPaths(),
            ]))
            : array_values(array_diff($this->comparisonPaths(), [$activePath], $referencePaths));

        sort($comparisonPaths);

        foreach ($comparisonPaths as $path) {
            $scan = $environmentScans[$path] ?? null;

            if (! is_array($scan) || ! $scan['exists']) {
                continue;
            }

            foreach (array_keys($activeDeclared) as $key) {
                $meta = $scan['keys'][$key] ?? null;

                if (is_array($meta) && ($path !== $activePath || ! $meta['commented'])) {
                    continue;
                }

                if (basename($path) === '.env.testing' && isset($phpUnitKeys[$key])) {
                    continue;
                }

                if ($path === $activePath && $this->runtimeHas($key)) {
                    continue;
                }

                if ($this->isIgnored($key)) {
                    continue;
                }

                $findings[] = $this->finding(
                    'warning',
                    'missing-from-environment-file',
                    $key,
                    sprintf('Environment key %s is present in another scanned environment file but missing from %s.', $key, basename($path)),
                    $path,
                    null,
                );
            }
        }

        foreach ($declared as $key => $locations) {
            if (isset($usages[$key]) || $this->isIgnored($key)) {
                continue;
            }

            $activeLocations = array_values(array_filter(
                $locations,
                static fn (array $location): bool => ! $location['commented'],
            ));

            if ($activeLocations === []) {
                continue;
            }

            $first = $activeLocations[0];
            $findings[] = $this->finding(
                'warning',
                'possibly-unused-key',
                $key,
                sprintf('Environment key %s is declared but no application-owned usage was found.', $key),
                $first['path'],
                $first['line'],
            );
        }

        return $this->deduplicateAndSort($findings);
    }

    /** @return list<string> */
    private function collectSourceFiles(): array
    {
        $files = [];
        $maxSize = max(1, (int) config('env-guard.max_file_size', 1_048_576));
        $configuredPaths = (array) config('env-guard.scan_paths', []);
        $configuredPaths[] = $this->app->configPath();
        $paths = [];

        foreach ($configuredPaths as $configuredPath) {
            if (! is_string($configuredPath) || $configuredPath === '') {
                continue;
            }

            $paths[] = $this->resolveBasePath($configuredPath);
        }

        foreach (array_unique($paths) as $path) {
            if ($this->isExcludedSourcePath($path)) {
                continue;
            }

            if (is_file($path)) {
                $this->maybeAddSourceFile($files, $path, $maxSize);

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $info */
            foreach ($this->sourceFilesIn($path) as $info) {
                if (! $info->isFile()) {
                    continue;
                }

                $this->maybeAddSourceFile($files, $info->getPathname(), $maxSize);
            }
        }

        foreach ((array) config('env-guard.project_files', []) as $configuredFile) {
            if (is_string($configuredFile) && $configuredFile !== '') {
                $this->maybeAddSourceFile($files, $this->resolveBasePath($configuredFile), $maxSize, true);
            }
        }

        foreach ((array) config('env-guard.project_directories', []) as $configuredDirectory) {
            if (! is_string($configuredDirectory) || $configuredDirectory === '') {
                continue;
            }

            $directory = $this->resolveBasePath($configuredDirectory);

            if (! is_dir($directory) || $this->isExcludedSourcePath($directory)) {
                continue;
            }

            /** @var SplFileInfo $info */
            foreach ($this->sourceFilesIn($directory) as $info) {
                if ($info->isFile()) {
                    $this->maybeAddSourceFile($files, $info->getPathname(), $maxSize, true);
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @return iterable<int|string, SplFileInfo> */
    private function sourceFilesIn(string $directory): iterable
    {
        $iterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator(
            $iterator,
            function (SplFileInfo $info): bool {
                if ($info->isLink()) {
                    return false;
                }

                return ! $this->isExcludedSourcePath($info->getPathname());
            },
        );

        return new RecursiveIteratorIterator($filtered);
    }

    private function isExcludedSourcePath(string $path): bool
    {
        $base = rtrim($this->normalizeComparablePath($this->app->basePath()), '/');
        $normalized = $this->normalizeComparablePath($path);

        if ($normalized === $base) {
            return false;
        }

        $prefix = $base.'/';

        if (! str_starts_with($normalized, $prefix)) {
            return false;
        }

        $relative = ltrim(substr($normalized, strlen($prefix)), '/');

        foreach (['.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache'] as $directory) {
            if ($relative === $directory || str_starts_with($relative, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparablePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    /** @param list<string> $files */
    private function maybeAddSourceFile(array &$files, string $path, int $maxSize, bool $allowAnyText = false): void
    {
        if ($this->isExcludedSourcePath($path) || ! is_file($path) || ! is_readable($path) || is_link($path)) {
            return;
        }

        $size = filesize($path);

        if ($size === false || $size > $maxSize) {
            return;
        }

        if ($allowAnyText && ! $this->isLikelyTextFile($path)) {
            return;
        }

        $normalized = strtolower(str_replace('\\', '/', $path));
        $extensions = ['.php', '.blade.php', '.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx', '.mts', '.cts', '.vue', '.svelte', '.json', '.xml', '.yml', '.yaml', '.sh'];

        $supported = $allowAnyText;

        if (! $supported) {
            foreach ($extensions as $extension) {
                if (str_ends_with($normalized, $extension)) {
                    $supported = true;
                    break;
                }
            }
        }

        if ($supported) {
            $files[] = $path;
        }
    }

    private function isLikelyTextFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $sample = fread($handle, 4096);
        fclose($handle);

        return $sample !== false && ! str_contains($sample, "\0");
    }

    /** @return list<string> */
    private function environmentPaths(): array
    {
        $paths = [
            $this->app->environmentFilePath(),
            ...$this->referencePaths(),
            ...$this->comparisonPaths(),
            ...$this->discoveredEnvironmentPaths(),
        ];

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function comparisonPaths(): array
    {
        $paths = [];

        foreach ((array) config('env-guard.compare_files', []) as $file) {
            if (is_string($file) && $file !== '') {
                $paths[] = $this->resolveEnvironmentPath($file);
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function discoveredEnvironmentPaths(): array
    {
        if (! (bool) config('env-guard.discover_environment_files', true)) {
            return [];
        }

        $environmentPath = $this->app->environmentPath();

        if (! is_dir($environmentPath)) {
            return [];
        }

        $root = rtrim($environmentPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $paths = [];
        $plainEnvironment = $root.'.env';

        if (is_file($plainEnvironment) && ! $this->excludedEnvironmentFile(basename($plainEnvironment))) {
            $paths[] = $plainEnvironment;
        }

        foreach (glob($root.'.env.*') ?: [] as $path) {
            if (! is_file($path) || $this->excludedEnvironmentFile(basename($path))) {
                continue;
            }

            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function referencePaths(): array
    {
        $paths = [];

        foreach ((array) config('env-guard.reference_files', []) as $file) {
            if (is_string($file) && $file !== '') {
                $paths[] = $this->resolveEnvironmentPath($file);
            }
        }

        return array_values(array_unique($paths));
    }

    private function excludedEnvironmentFile(string $name): bool
    {
        if (str_ends_with($name, '.encrypted')) {
            return true;
        }

        foreach (['.bak', '.backup', '.old'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveBasePath(string $path): string
    {
        return $this->isAbsolutePath($path) ? $path : $this->app->basePath($path);
    }

    private function resolveEnvironmentPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($this->app->environmentPath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function removeLegacyResultCache(): void
    {
        $paths = [
            $this->app->storagePath('framework/cache/laravel-env-guard.json'),
        ];
        $configured = config('env-guard.cache_path');

        if (is_string($configured) && $configured !== '') {
            $paths[] = $this->isAbsolutePath($configured)
                ? $configured
                : $this->app->basePath($configured);
        }

        foreach (array_unique($paths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function runtimeHas(string $key): bool
    {
        try {
            return Env::get($key) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function isIgnored(string $key): bool
    {
        $exact = array_merge(
            (array) config('env-guard.known_external_keys', []),
            (array) config('env-guard.ignore_keys', []),
        );

        if (in_array($key, $exact, true)) {
            return true;
        }

        foreach ((array) config('env-guard.ignore_patterns', []) as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function finding(
        string $severity,
        string $code,
        ?string $key,
        string $message,
        ?string $path,
        ?int $line,
    ): array {
        return array_filter([
            'severity' => $severity,
            'code' => $code,
            'key' => $key,
            'message' => $message,
            'path' => $path === null ? null : $this->relativePath($path),
            'line' => $line,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';
        $normalized = str_replace('\\', '/', $path);
        $comparableBase = rtrim($this->normalizeComparablePath($this->app->basePath()), '/').'/';
        $comparablePath = $this->normalizeComparablePath($path);

        return str_starts_with($comparablePath, $comparableBase)
            ? substr($normalized, strlen($base))
            : $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    private function deduplicateAndSort(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $id = implode('|', [
                $finding['severity'] ?? '',
                $finding['code'] ?? '',
                $finding['key'] ?? '',
                $finding['path'] ?? '',
                $finding['line'] ?? '',
            ]);
            $unique[$id] = $finding;
        }

        $findings = array_values($unique);

        usort($findings, static function (array $left, array $right): int {
            $severity = ['error' => 0, 'warning' => 1];

            return [
                $severity[$left['severity'] ?? 'warning'] ?? 2,
                $left['code'] ?? '',
                $left['key'] ?? '',
                $left['path'] ?? '',
                $left['line'] ?? 0,
            ] <=> [
                $severity[$right['severity'] ?? 'warning'] ?? 2,
                $right['code'] ?? '',
                $right['key'] ?? '',
                $right['path'] ?? '',
                $right['line'] ?? 0,
            ];
        });

        return $findings;
    }
}
