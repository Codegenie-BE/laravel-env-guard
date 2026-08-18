from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"Expected exactly one match in {path}, found {count}: {old[:120]!r}")
    file.write_text(text.replace(old, new, 1))


def append_once(path: str, marker: str, addition: str) -> None:
    file = Path(path)
    text = file.read_text()
    if addition.strip() in text:
        raise SystemExit(f"Addition already present in {path}")
    if marker not in text:
        raise SystemExit(f"Marker not found in {path}: {marker!r}")
    file.write_text(text.replace(marker, addition + marker, 1))


# EnvGuard: prune dependency/generated trees even when callers scan the project root.
replace_once(
    'src/EnvGuard.php',
    'use RecursiveDirectoryIterator;\nuse RecursiveIteratorIterator;',
    'use RecursiveCallbackFilterIterator;\nuse RecursiveDirectoryIterator;\nuse RecursiveIteratorIterator;',
)

replace_once(
    'src/EnvGuard.php',
    '''        foreach (array_unique($paths) as $path) {
            if (is_file($path)) {
                $this->maybeAddSourceFile($files, $path, $maxSize);

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $info */
            foreach ($iterator as $info) {
                if ($info->isLink() || ! $info->isFile()) {
                    continue;
                }

                $normalized = str_replace('\\\\', '/', $info->getPathname());

                if (str_contains($normalized, '/bootstrap/cache/') || str_contains($normalized, '/storage/')) {
                    continue;
                }

                $this->maybeAddSourceFile($files, $info->getPathname(), $maxSize);
            }
        }
''',
    '''        foreach (array_unique($paths) as $path) {
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
''',
)

replace_once(
    'src/EnvGuard.php',
    '''            $directory = $this->resolveBasePath($configuredDirectory);

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $info */
            foreach ($iterator as $info) {
                if (! $info->isLink() && $info->isFile()) {
                    $this->maybeAddSourceFile($files, $info->getPathname(), $maxSize, true);
                }
            }
''',
    '''            $directory = $this->resolveBasePath($configuredDirectory);

            if (! is_dir($directory) || $this->isExcludedSourcePath($directory)) {
                continue;
            }

            /** @var SplFileInfo $info */
            foreach ($this->sourceFilesIn($directory) as $info) {
                if ($info->isFile()) {
                    $this->maybeAddSourceFile($files, $info->getPathname(), $maxSize, true);
                }
            }
''',
)

replace_once(
    'src/EnvGuard.php',
    '''    /** @param list<string> $files */
    private function maybeAddSourceFile(array &$files, string $path, int $maxSize, bool $allowAnyText = false): void
    {
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            return;
        }
''',
    '''    private function sourceFilesIn(string $directory): RecursiveIteratorIterator
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
        $base = rtrim(strtolower(str_replace('\\\\', '/', $this->app->basePath())), '/');
        $normalized = strtolower(str_replace('\\\\', '/', $path));

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

    /** @param list<string> $files */
    private function maybeAddSourceFile(array &$files, string $path, int $maxSize, bool $allowAnyText = false): void
    {
        if ($this->isExcludedSourcePath($path) || ! is_file($path) || ! is_readable($path) || is_link($path)) {
            return;
        }
''',
)

# PHP scanner: recognize the normal PHP named-argument form env(key: 'FOO').
replace_once(
    'src/Scanners/PhpEnvironmentScanner.php',
    '        $argumentIndex = $this->nextSignificantIndex($tokens, $openIndex);\n\n        if ($argumentIndex === null || ! is_array($tokens[$argumentIndex]) || $tokens[$argumentIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {',
    '        $argumentIndex = $this->environmentKeyArgumentIndex($tokens, $openIndex);\n\n        if ($argumentIndex === null || ! is_array($tokens[$argumentIndex]) || $tokens[$argumentIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {',
)

replace_once(
    'src/Scanners/PhpEnvironmentScanner.php',
    '''    /** @param array<int, array<int, mixed>|string> $tokens */
    private function literalKeyAt(array $tokens, int $index): ?string
''',
    '''    /** @param array<int, array<int, mixed>|string> $tokens */
    private function environmentKeyArgumentIndex(array $tokens, int $openIndex): ?int
    {
        $index = $this->nextSignificantIndex($tokens, $openIndex);

        if ($index === null) {
            return null;
        }

        $token = $tokens[$index];

        if (! is_array($token) || $token[0] !== T_STRING) {
            return $index;
        }

        $colonIndex = $this->nextSignificantIndex($tokens, $index);

        if ($colonIndex === null || $tokens[$colonIndex] !== ':') {
            return $index;
        }

        if (strtolower($token[1]) !== 'key') {
            return null;
        }

        return $this->nextSignificantIndex($tokens, $colonIndex);
    }

    /** @param array<int, array<int, mixed>|string> $tokens */
    private function literalKeyAt(array $tokens, int $index): ?string
''',
)

# Text scanner: mask comments while preserving byte offsets/newlines used for diagnostics.
replace_once(
    'src/Scanners/TextEnvironmentScanner.php',
    '''            if ($contents === false) {
                continue;
            }

            $basename = basename($file);
''',
    '''            if ($contents === false) {
                continue;
            }

            $contents = $this->maskComments($contents, $file);
            $basename = basename($file);
''',
)

replace_once(
    'src/Scanners/TextEnvironmentScanner.php',
    '''    /** @param list<array{key:string, path:string, line:int, source:string}> $target */
    private function collectPattern(
''',
    '''    private function maskComments(string $contents, string $file): string
    {
        $contents = $this->maskPattern($contents, '/<!--.*?-->/s');

        if (str_ends_with($file, '.blade.php')) {
            $contents = $this->maskPattern($contents, '/\\{\\{--.*?--\\}\\}/s');
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array($extension, ['js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte'], true)) {
            $contents = $this->maskPattern(
                $contents,
                '~([\\'"`])(?:\\\\.|(?!\\1)[\\s\\S])*\\1(*SKIP)(*F)|//[^\\r\\n]*|/\\*[\\s\\S]*?\\*/~',
            );
        }

        if ($this->isInfrastructureFile($file)) {
            $contents = $this->maskPattern(
                $contents,
                '~([\\'"])(?:\\\\.|(?!\\1).)*\\1(*SKIP)(*F)|\\#[^\\r\\n]*~',
            );
        }

        return $contents;
    }

    private function maskPattern(string $contents, string $pattern): string
    {
        return preg_replace_callback(
            $pattern,
            static fn (array $match): string => preg_replace('/[^\\r\\n]/', ' ', $match[0]) ?? $match[0],
            $contents,
        ) ?? $contents;
    }

    /** @param list<array{key:string, path:string, line:int, source:string}> $target */
    private function collectPattern(
''',
)

# Regression: root scans still scan application code but never dependency/generated trees.
append_once(
    'tests/Feature/EnvGuardTest.php',
    "it('reuses cached findings until relevant file metadata changes', function () {",
    '''it('prunes dependency and generated directories when the project root is scanned', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\\n");
    file_put_contents($this->root.'/config/app.php', "<?php return ['name' => env('APP_NAME')];\\n");
    file_put_contents($this->root.'/app/Visible.php', "<?php env('VISIBLE_ROOT_KEY');\\n");

    foreach (['vendor/package', 'node_modules/package', '.git/hooks', 'storage/logs'] as $directory) {
        mkdir($this->root.'/'.$directory, 0777, true);
        file_put_contents($this->root.'/'.$directory.'/Ignored.php', "<?php env('IGNORED_".strtoupper(str_replace(['/', '.'], '_', $directory))."');\\n");
    }

    file_put_contents($this->root.'/bootstrap/cache/Ignored.php', "<?php env('IGNORED_BOOTSTRAP_CACHE');\\n");

    $result = buildEnvGuard($this->root, [
        'scan_paths' => [$this->root],
        'project_directories' => [$this->root],
    ])->inspect();
    $keys = array_values(array_filter(array_column($result['findings'], 'key')));

    expect($keys)->toContain('VISIBLE_ROOT_KEY')
        ->and($keys)->not->toContain(
            'IGNORED_VENDOR_PACKAGE',
            'IGNORED_NODE_MODULES_PACKAGE',
            'IGNORED__GIT_HOOKS',
            'IGNORED_STORAGE_LOGS',
            'IGNORED_BOOTSTRAP_CACHE',
        );
});

''',
)

append_once(
    'tests/Unit/PhpEnvironmentScannerTest.php',
    "it('detects facade and raw environment access without scanning comments or strings', function () {",
    '''it('recognizes literal environment keys passed with named arguments', function () {
    file_put_contents($this->root.'/config/services.php', <<<'PHPFILE'
<?php
return ['token' => env(key: 'NAMED_CONFIG')];
PHPFILE);
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
use Illuminate\\Support\\Env as LaravelEnv;

$one = env(key: 'NAMED_OUTSIDE');
$two = LaravelEnv::get(key: 'NAMED_FACADE');
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/config/services.php',
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect(array_column($result['usages'], 'key'))->toBe(['NAMED_CONFIG', 'NAMED_OUTSIDE', 'NAMED_FACADE'])
        ->and($result['dynamic'])->toBe([]);
});

''',
)

append_once(
    'tests/Unit/TextEnvironmentScannerTest.php',
    "it('detects Vite loadEnv access', function () {",
    '''it('ignores environment references inside text comments', function () {
    $root = sys_get_temp_dir().'/env-guard-comments-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);

    $vite = $root.'/app.ts';
    $blade = $root.'/view.blade.php';
    $phpunit = $root.'/phpunit.xml';
    $compose = $root.'/compose.yaml';

    file_put_contents($vite, <<<'JS'
// import.meta.env.VITE_LINE_COMMENT
/* import.meta.env.VITE_BLOCK_COMMENT */
const actual = import.meta.env.VITE_REAL;
JS);
    file_put_contents($blade, "{{-- {{ env('BLADE_COMMENT') }} --}}\\n{{ env('BLADE_REAL') }}\\n");
    file_put_contents($phpunit, '<php><!-- <env name="COMMENTED_TEST" value="1"/> --><env name="REAL_TEST" value="1"/></php>');
    file_put_contents($compose, "# COMMENTED_INFRA: \\${COMMENTED_INFRA}\\nservices:\\n  app:\\n    environment:\\n      REAL_INFRA: \\${REAL_INFRA}\\n      LABEL: \"#\\${QUOTED_INFRA}\"\\n");

    $result = (new TextEnvironmentScanner)->scan([$vite, $blade, $phpunit, $compose], [
        'COMMENTED_INFRA',
        'REAL_INFRA',
        'QUOTED_INFRA',
    ]);
    $usageKeys = array_column($result['usages'], 'key');

    expect($usageKeys)->toContain('VITE_REAL', 'REAL_INFRA', 'QUOTED_INFRA')
        ->and($usageKeys)->not->toContain('VITE_LINE_COMMENT', 'VITE_BLOCK_COMMENT', 'COMMENTED_INFRA')
        ->and(array_column($result['blade_env'], 'key'))->toBe(['BLADE_REAL'])
        ->and($result['phpunit_keys'])->toBe(['REAL_TEST']);

    foreach ([$vite, $blade, $phpunit, $compose] as $file) {
        @unlink($file);
    }

    @rmdir($root);
});

''',
)

replace_once(
    'README.md',
    'The package deliberately does not scan `vendor/`. Scanning every dependency would make optional package variables appear to be mandatory application variables and would substantially increase boot-time work.',
    'The package deliberately prunes `vendor/`, `node_modules/`, `.git/`, `storage/`, and `bootstrap/cache/` even when a configured scan path points at the project root. Scanning dependencies or generated state would create false environment requirements and unnecessary boot-time work.',
)

replace_once(
    'CHANGELOG.md',
    '- The existing 80% test-coverage requirement is now enforced by the permanent GitHub Actions quality job.',
    '- The existing 80% test-coverage requirement is now enforced by the permanent GitHub Actions quality job.\n- Recursive root scans now prune dependency, VCS, storage, and bootstrap cache trees before traversal.\n- Text scanning now masks Blade, XML/HTML, JavaScript-style, and infrastructure comments while preserving diagnostic line offsets.\n- PHP environment scanning now recognizes literal `key:` named arguments for `env()` and `Env::get()`.',
)
