from pathlib import Path


def replace_once(path: str, old: str, new: str, expected: int = 1) -> None:
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != expected:
        raise SystemExit(f"Expected {expected} match(es) in {path}, found {count}: {old!r}")
    file.write_text(text.replace(old, new, expected))


# Align dotenv variable-name parsing with current vlucas/phpdotenv.
replace_once(
    "src/Scanners/EnvironmentFileScanner.php",
    "$name = '[\\p{L}_][\\p{L}\\p{N}_.-]*';",
    "$name = '[\\p{Ll}\\p{Lu}\\p{M}\\p{N}_.]+';",
)
replace_once(
    "src/Scanners/EnvironmentFileScanner.php",
    """            if (! preg_match('/^(?:export\\s+)?('.$name.')\\s*=(.*)$/u', $candidate, $matches)) {
                continue;
            }

            $key = $matches[1];
            $value = ltrim($matches[2]);""",
    """            $pattern = '/^(?:export\\s+)?(?:([\"\\x27])('.$name.')\\1|('.$name.'))\\s*=(.*)$/u';

            if (! preg_match($pattern, $candidate, $matches, PREG_UNMATCHED_AS_NULL)) {
                continue;
            }

            $key = $matches[2] ?? $matches[3] ?? null;

            if ($key === null) {
                continue;
            }

            $value = ltrim($matches[4]);""",
)

# Ignore/external keys should be exempt consistently from example parity.
replace_once(
    "src/EnvGuard.php",
    """            foreach ($activeScan['keys'] as $key => $meta) {
                if (! isset($documentedKeys[$key])) {
                    $findings[] = $this->finding(
                        'warning',
                        'missing-from-example',
                        $key,
                        sprintf('Environment key %s exists in the active environment file but is not documented in .env.example.', $key),
                        $activePath,
                        $meta['line'],
                    );
                }
            }""",
    """            foreach ($activeScan['keys'] as $key => $meta) {
                if ($this->isIgnored($key) || isset($documentedKeys[$key])) {
                    continue;
                }

                $findings[] = $this->finding(
                    'warning',
                    'missing-from-example',
                    $key,
                    sprintf('Environment key %s exists in the active environment file but is not documented in .env.example.', $key),
                    $activePath,
                    $meta['line'],
                );
            }""",
)

# Completeness checks should apply only to explicitly configured standalone files.
replace_once(
    "src/EnvGuard.php",
    "$comparisonPaths = array_values(array_diff($environmentPaths, [$activePath], $referencePaths));",
    "$comparisonPaths = array_values(array_diff($this->comparisonPaths(), [$activePath], $referencePaths));",
)
replace_once(
    "src/EnvGuard.php",
    """        $paths = [$this->app->environmentFilePath(), ...$this->referencePaths()];
        $environmentPath = $this->app->environmentPath();

        foreach ((array) config('env-guard.compare_files', []) as $file) {
            if (is_string($file) && $file !== '') {
                $paths[] = $this->resolveEnvironmentPath($file);
            }
        }""",
    """        $paths = [
            $this->app->environmentFilePath(),
            ...$this->referencePaths(),
            ...$this->comparisonPaths(),
        ];
        $environmentPath = $this->app->environmentPath();""",
)
replace_once(
    "src/EnvGuard.php",
    """    /** @return list<string> */
    private function referencePaths(): array
    {""",
    """    /** @return list<string> */
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
    private function referencePaths(): array
    {""",
)

# Resolve source roots before deduplication so config/ is not traversed twice.
replace_once(
    "src/EnvGuard.php",
    """        $files = [];
        $maxSize = max(1, (int) config('env-guard.max_file_size', 1_048_576));
        $paths = (array) config('env-guard.scan_paths', []);
        $paths[] = $this->app->configPath();

        foreach (array_unique($paths) as $configuredPath) {
            if (! is_string($configuredPath) || $configuredPath === '') {
                continue;
            }

            $path = $this->resolveBasePath($configuredPath);

            if (is_file($path)) {""",
    """        $files = [];
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
            if (is_file($path)) {""",
)
replace_once(
    "src/EnvGuard.php",
    "$extensions = ['.php', '.blade.php', '.js', '.jsx', '.ts', '.tsx', '.vue', '.svelte', '.json', '.xml', '.yml', '.yaml', '.sh'];",
    "$extensions = ['.php', '.blade.php', '.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx', '.mts', '.cts', '.vue', '.svelte', '.json', '.xml', '.yml', '.yaml', '.sh'];",
)

# Vite/Node property names are JavaScript identifiers, not uppercase-only.
text_scanner = "src/Scanners/TextEnvironmentScanner.php"
for old, new in [
    ("'/\\bimport\\.meta\\.env\\.([A-Z][A-Z0-9_]*)/'", "'/\\bimport\\.meta\\.env\\.([A-Za-z_$][A-Za-z0-9_$]*)/'"),
    ("'/\\bimport\\.meta\\.env\\s*\\[\\s*[\\'\\\"]([A-Z][A-Z0-9_]*)[\\'\\\"]\\s*\\]/'", "'/\\bimport\\.meta\\.env\\s*\\[\\s*[\\'\\\"]([A-Za-z0-9_.]+)[\\'\\\"]\\s*\\]/'"),
    ("'/\\bprocess\\.env\\.([A-Z][A-Z0-9_]*)/'", "'/\\bprocess\\.env\\.([A-Za-z_$][A-Za-z0-9_$]*)/'"),
    ("'/\\bprocess\\.env\\s*\\[\\s*[\\'\\\"]([A-Z][A-Z0-9_]*)[\\'\\\"]\\s*\\]/'", "'/\\bprocess\\.env\\s*\\[\\s*[\\'\\\"]([A-Za-z0-9_.]+)[\\'\\\"]\\s*\\]/'"),
    ("'/\\b'.$quotedVariable.'\\.([A-Z][A-Z0-9_]*)/'", "'/\\b'.$quotedVariable.'\\.([A-Za-z_$][A-Za-z0-9_$]*)/'"),
    ("'/\\b'.$quotedVariable.'\\s*\\[\\s*[\\'\\\"]([A-Z][A-Z0-9_]*)[\\'\\\"]\\s*\\]/'", "'/\\b'.$quotedVariable.'\\s*\\[\\s*[\\'\\\"]([A-Za-z0-9_.]+)[\\'\\\"]\\s*\\]/'"),
    ("'/(?:^|,)\\s*([A-Z][A-Z0-9_]*)\\b/'", "'/(?:^|,)\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\b/'"),
]:
    replace_once(text_scanner, old, new)

replace_once(
    text_scanner,
    """            if (str_ends_with($file, '.blade.php') && preg_match_all""",
    """            $this->collectDirectDestructuredLoadEnv(
                $result['usages'],
                $contents,
                $file,
                $viteFilter,
            );

            if (str_ends_with($file, '.blade.php') && preg_match_all""",
)
replace_once(
    text_scanner,
    """    /**
     * @param  list<array{key:string, path:string, line:int, source:string}>  $target
     * @param  array<string, true>  $declaredLookup
     */
    private function collectInfrastructureUsages""",
    """    /**
     * @param  list<array{key:string, path:string, line:int, source:string}>  $target
     * @param  callable(string): bool  $filter
     */
    private function collectDirectDestructuredLoadEnv(
        array &$target,
        string $contents,
        string $file,
        callable $filter,
    ): void {
        $pattern = '/\\b(?:const|let|var)\\s*\\{([^}]*)\\}\\s*=\\s*loadEnv\\s*\\(/';

        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as [$members, $membersOffset]) {
            if (! preg_match_all('/(?:^|,)\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\b/', $members, $keys, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($keys[1] as [$key, $offset]) {
                if (! $filter($key)) {
                    continue;
                }

                $target[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $membersOffset + $offset), "\\n") + 1,
                    'source' => 'vite-load-env',
                ];
            }
        }
    }

    /**
     * @param  list<array{key:string, path:string, line:int, source:string}>  $target
     * @param  array<string, true>  $declaredLookup
     */
    private function collectInfrastructureUsages""",
)

# Conservative defaults and common Vite config module variants.
replace_once("config/env-guard.php", "'discover_environment_files' => true,", "'discover_environment_files' => false,")
replace_once(
    "config/env-guard.php",
    """        'vite.config.js',
        'vite.config.ts',""",
    """        'vite.config.js',
        'vite.config.mjs',
        'vite.config.cjs',
        'vite.config.ts',
        'vite.config.mts',
        'vite.config.cts',""",
)

# Scanner regression tests.
env_test = Path("tests/Unit/EnvironmentFileScannerTest.php")
env_test.write_text(env_test.read_text() + r'''

it('matches phpdotenv variable-name rules including quoted and numeric names', function () {
    $path = tempnam(sys_get_temp_dir(), 'env-guard-');
    file_put_contents($path, <<<'ENV'
1ST_KEY=value
"QUOTED.KEY"=value
UNICØDE=value
INVALID-KEY=value
ENV);

    $result = (new EnvironmentFileScanner)->scan($path);

    expect(array_keys($result['keys']))->toBe(['1ST_KEY', 'QUOTED.KEY', 'UNICØDE']);

    @unlink($path);
});
''')

text_test = Path("tests/Unit/TextEnvironmentScannerTest.php")
text_test.write_text(text_test.read_text() + r'''

it('detects lowercase Vite keys and direct loadEnv destructuring', function () {
    $path = tempnam(sys_get_temp_dir(), 'vite-');
    file_put_contents($path, <<<'JS'
import { loadEnv } from 'vite';
console.log(import.meta.env.VITE_lowercase);
console.log(import.meta.env['VITE_name.with.dot']);
const { VITE_direct, APP_port: port } = loadEnv(mode, process.cwd(), '');
JS);

    $result = (new TextEnvironmentScanner)->scan([$path], ['APP_port']);
    $keys = array_column($result['usages'], 'key');

    expect($keys)->toContain('VITE_lowercase', 'VITE_name.with.dot', 'VITE_direct', 'APP_port');

    @unlink($path);
});
''')

# Direct core-flow coverage: decisions, comparison semantics, cache and secret invariant.
Path("tests/Feature/EnvGuardTest.php").write_text(r'''<?php

use Codegenie\EnvGuard\EnvGuard;
use Codegenie\EnvGuard\Scanners\EnvironmentFileScanner;
use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;
use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;
use Illuminate\Foundation\Application;

function buildEnvGuard(string $root, array $overrides = []): EnvGuard
{
    $app = new Application($root);
    $app->useEnvironmentPath($root);
    $app->loadEnvironmentFrom('.env');
    $app->useConfigPath($root.'/config');
    $app->useStoragePath($root.'/storage');
    $app->usePublicPath($root.'/public');

    $defaults = [
        'scan_paths' => [$root.'/app'],
        'project_files' => [],
        'project_directories' => [],
        'reference_files' => ['.env.example'],
        'compare_files' => ['.env.testing'],
        'discover_environment_files' => false,
        'max_file_size' => 1_048_576,
        'known_external_keys' => [],
        'ignore_keys' => [],
        'ignore_patterns' => [],
        'cache_path' => $root.'/storage/env-guard.json',
    ];

    foreach ([...$defaults, ...$overrides] as $key => $value) {
        config()->set('env-guard.'.$key, $value);
    }

    return new EnvGuard($app, new EnvironmentFileScanner, new PhpEnvironmentScanner, new TextEnvironmentScanner);
}

function findingCodesFor(array $findings, string $key): array
{
    return array_values(array_map(
        static fn (array $finding): string => $finding['code'],
        array_filter($findings, static fn (array $finding): bool => ($finding['key'] ?? null) === $key),
    ));
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/env-guard-core-'.bin2hex(random_bytes(5));

    foreach (['app', 'bootstrap/cache', 'config', 'public', 'storage'] as $directory) {
        mkdir($this->root.'/'.$directory, 0777, true);
    }
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($this->root);
});

it('audits active, reference and comparison files without leaking values', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\nLOCAL_ONLY=local-secret\nIGNORED_ONLY=ignore-secret\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\nSERVICE_TOKEN=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");
    file_put_contents($this->root.'/config/services.php', "<?php return ['token' => env('SERVICE_TOKEN')];\n");
    file_put_contents($this->root.'/app/Bad.php', "<?php env('UNDECLARED_KEY'); getenv('APP_NAME'); env(\$dynamic);\n");

    $result = buildEnvGuard($this->root, ['ignore_keys' => ['IGNORED_ONLY']])->inspect();

    expect(findingCodesFor($result['findings'], 'LOCAL_ONLY'))->toContain('missing-from-example', 'possibly-unused-key')
        ->and(findingCodesFor($result['findings'], 'IGNORED_ONLY'))->toBe([])
        ->and(findingCodesFor($result['findings'], 'SERVICE_TOKEN'))->toContain('missing-from-active', 'missing-from-environment-file')
        ->and(findingCodesFor($result['findings'], 'UNDECLARED_KEY'))->toContain('env-outside-config', 'used-but-undeclared')
        ->and(findingCodesFor($result['findings'], 'APP_NAME'))->toContain('raw-environment-access')
        ->and(array_column($result['findings'], 'code'))->toContain('dynamic-env-key')
        ->and(json_encode($result['findings']))->not->toContain('local-secret', 'ignore-secret');
});

it('discovers extra env files without treating layered files as standalone comparisons', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.production', "PROD_ONLY=one\nPROD_ONLY=two\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $result = buildEnvGuard($this->root, ['discover_environment_files' => true])->inspect();
    $productionMissing = array_filter(
        $result['findings'],
        static fn (array $finding): bool => ($finding['code'] ?? null) === 'missing-from-environment-file'
            && ($finding['path'] ?? null) === '.env.production',
    );

    expect($productionMissing)->toBe([])
        ->and(array_column($result['findings'], 'code'))->toContain('duplicate-key');
});

it('checks explicitly configured standalone environment files for completeness', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.staging', "");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $result = buildEnvGuard($this->root, ['compare_files' => ['.env.testing', '.env.staging']])->inspect();
    $matches = array_filter(
        $result['findings'],
        static fn (array $finding): bool => ($finding['code'] ?? null) === 'missing-from-environment-file'
            && ($finding['key'] ?? null) === 'APP_NAME'
            && ($finding['path'] ?? null) === '.env.staging',
    );

    expect($matches)->toHaveCount(1);
});

it('reuses cached findings until relevant file metadata changes', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\n");

    $guard = buildEnvGuard($this->root);
    $first = $guard->inspect();
    $second = $guard->inspect();

    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')]; // changed\n");
    clearstatcache();
    $third = $guard->inspect();

    expect($first['fresh'])->toBeTrue()
        ->and($second['fresh'])->toBeFalse()
        ->and($third['fresh'])->toBeTrue();
});
''')

# Documentation aligned with the new conservative comparison model.
replace_once("README.md", "'discover_environment_files' => true,", "'discover_environment_files' => false,")
replace_once(
    "README.md",
    """        'vite.config.js',
        'vite.config.ts',""",
    """        'vite.config.js',
        'vite.config.mjs',
        'vite.config.cjs',
        'vite.config.ts',
        'vite.config.mts',
        'vite.config.cts',""",
)
replace_once(
    "README.md",
    """- additional `.env.[APP_ENV]` files;
- environment variables supplied by `phpunit.xml`;""",
    """- explicitly configured standalone environment files such as `.env.testing`;
- optional discovery of additional `.env.*` files for diagnostics without assuming they are complete standalone environments;
- environment variables supplied by `phpunit.xml`;""",
)
replace_once(
    "README.md",
    """When `CACHE_STORE` is absent from `.env.testing` but present in `phpunit.xml`, the guard treats it as supplied for the testing environment.

## Commented example keys""",
    """When `CACHE_STORE` is absent from `.env.testing` but present in `phpunit.xml`, the guard treats it as supplied for the testing environment.

Completeness checks apply only to files listed in `compare_files`. Automatic `.env.*` discovery is disabled by default because Vite files such as `.env.local` and `.env.production` may layer on top of `.env` instead of replacing it. Enable discovery when you want diagnostics for additional files, or list a known standalone Laravel environment file explicitly in `compare_files`.

## Commented example keys""",
)
replace_once(
    "docs/scenarios.md",
    """Existing `.env.*` files are discovered, excluding encrypted and obvious backup/distribution files.

For additional files, keys actually used by the application are checked for presence. This is a warning rather than a blocking error because environment-specific files can intentionally rely on defaults or externally supplied values.""",
    """Automatic `.env.*` discovery is disabled by default, because Laravel standalone environment files and Vite's layered `.env.local` / `.env.[mode]` files have different completeness semantics. The active Laravel file is always inspected, while known standalone files such as `.env.testing` are listed explicitly in `compare_files`.

When discovery is enabled, additional files are still inspected for key-level diagnostics such as duplicates and casing, but missing-key completeness warnings are emitted only for files explicitly listed in `compare_files`.""",
)

changelog = Path("CHANGELOG.md")
changelog.write_text(changelog.read_text() + """
- Dotenv key parsing aligned with current `vlucas/phpdotenv`, including quoted names, Unicode and numeric-leading names while rejecting invalid hyphenated names.
- Conservative standalone-env comparison semantics so auto-discovered Vite layer files do not produce missing-key completeness noise.
- Lowercase/custom Vite key detection, direct `loadEnv()` destructuring, and common `.mjs` / `.cjs` / `.mts` / `.cts` Vite config variants.
- Core Env Guard regression coverage for ignored keys, explicit comparisons, discovery behavior, caching, and secret-value non-persistence.
- Deduplicated source roots before recursive scanning so the default `config` directory is not traversed twice.
""")
