from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"Expected one match in {path}, found {count}: {old[:100]!r}")
    file.write_text(text.replace(old, new, 1))


def insert_before(path: str, marker: str, addition: str) -> None:
    replace_once(path, marker, addition + marker)


# A used key must be documented by a reference file, not merely present in a
# comparison or discovered environment file.
replace_once(
    "src/EnvGuard.php",
    """        $declaredCaseInsensitive = [];

        foreach (array_keys($declared) as $key) {
            $declaredCaseInsensitive[strtolower($key)][] = $key;
        }

        foreach ($usages as $key => $locations) {
            if (isset($declared[$key]) || $this->isIgnored($key)) {
                continue;
            }

            $caseMatches = $declaredCaseInsensitive[strtolower($key)] ?? [];
""",
    """        $activeScan = $environmentScans[$activePath] ?? null;
        $referenceScans = array_values(array_filter(
            $environmentScans,
            static fn (array $scan): bool => in_array($scan['path'], $referencePaths, true),
        ));
        $documentedKeys = [];
        $documentedByCase = [];

        foreach ($referenceScans as $scan) {
            if (! $scan['exists']) {
                $findings[] = $this->finding(
                    'warning',
                    'missing-reference-file',
                    null,
                    sprintf('Reference environment file %s does not exist.', basename($scan['path'])),
                    $scan['path'],
                    null,
                );
            }

            foreach (array_keys($scan['keys']) as $key) {
                $documentedKeys[$key] = true;
                $documentedByCase[strtolower($key)][] = $key;
            }
        }

        foreach ($usages as $key => $locations) {
            if (isset($documentedKeys[$key]) || $this->isIgnored($key)) {
                continue;
            }

            $caseMatches = array_values(array_unique($documentedByCase[strtolower($key)] ?? []));
""",
)

replace_once(
    "src/EnvGuard.php",
    "sprintf('Environment key %s is referenced by the project but is not declared in any scanned environment file.', $key)",
    "sprintf('Environment key %s is referenced by the project but is not documented in any reference environment file.', $key)",
)

replace_once(
    "src/EnvGuard.php",
    """        $activeScan = $environmentScans[$activePath] ?? null;
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
                    sprintf('Reference environment file %s does not exist.', basename($scan['path'])),
                    $scan['path'],
                    null,
                );
            }

            foreach (array_keys($scan['keys']) as $key) {
                $documentedKeys[$key] = true;
            }
        }

""",
    "",
)

replace_once(
    "src/EnvGuard.php",
    "sprintf('Environment key %s exists in the active environment file but is not documented in .env.example.', $key)",
    "sprintf('Environment key %s exists in the active environment file but is not documented in any reference environment file.', $key)",
)

replace_once(
    "src/EnvGuard.php",
    """        foreach ($referenceScans as $scan) {
            if (! $scan['exists'] || ! is_array($activeScan) || ! $activeScan['exists']) {
                continue;
            }

            foreach ($scan['keys'] as $key => $meta) {
                if ($meta['commented'] || isset($activeScan['keys'][$key]) || $this->runtimeHas($key)) {
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
""",
    """        if (is_array($activeScan) && $activeScan['exists']) {
            $requiredActiveKeys = [];

            foreach ($referenceScans as $scan) {
                if (! $scan['exists']) {
                    continue;
                }

                foreach ($scan['keys'] as $key => $meta) {
                    if ($meta['commented']) {
                        continue;
                    }

                    $requiredActiveKeys[$key] = [
                        'reference' => basename($scan['path']),
                        'path' => $scan['path'],
                        'line' => $meta['line'],
                    ];
                }
            }

            foreach ($usages as $key => $locations) {
                if ($this->isIgnored($key)) {
                    continue;
                }

                $first = $locations[0] ?? [];
                $requiredActiveKeys[$key] ??= [
                    'reference' => null,
                    'path' => $first['path'] ?? null,
                    'line' => $first['line'] ?? null,
                ];
            }

            foreach ($requiredActiveKeys as $key => $requirement) {
                if (isset($activeScan['keys'][$key]) || $this->runtimeHas($key) || $this->isIgnored($key)) {
                    continue;
                }

                $message = is_string($requirement['reference'])
                    ? sprintf('Environment key %s is active in %s but missing from the active environment file.', $key, $requirement['reference'])
                    : sprintf('Environment key %s is used by the project but missing from the active environment file.', $key);

                $findings[] = $this->finding(
                    'warning',
                    'missing-from-active',
                    $key,
                    $message,
                    $activePath,
                    null,
                );
            }
        }
""",
)

# Explicit project_files are deliberate, so extensionless files such as
# Dockerfile must be scanned as text.
replace_once(
    "src/EnvGuard.php",
    "$this->maybeAddSourceFile($files, $this->resolveBasePath($configuredFile), $maxSize);",
    "$this->maybeAddSourceFile($files, $this->resolveBasePath($configuredFile), $maxSize, true);",
)

# External presence affects both reference and comparison completeness results.
replace_once(
    "src/EnvGuard.php",
    """        foreach ($this->referencePaths() as $path) {
            $scan = $this->environmentFiles->scan($path, true);
""",
    """        $paths = array_values(array_unique(array_merge(
            $this->referencePaths(),
            $this->comparisonPaths(),
        )));

        foreach ($paths as $path) {
            $scan = $this->environmentFiles->scan($path, true);
""",
)

# Parse both PHPUnit <env> and <server> entries.
replace_once(
    "src/Scanners/TextEnvironmentScanner.php",
    "if (preg_match_all('/<env\\s+[^>]*name\\s*=\\s*([\\'\\\"])([^\\'\\\"]+)\\1/i', $contents, $matches)) {",
    "if (preg_match_all('/<(?:env|server)\\s+[^>]*name\\s*=\\s*([\\'\\\"])([^\\'\\\"]+)\\1/i', $contents, $matches)) {",
)

# Replace regex-only JavaScript comment masking with a small lexer that masks
# comments, quoted strings and template text while preserving ${...} code.
replace_once(
    "src/Scanners/TextEnvironmentScanner.php",
    """        if (in_array($extension, ['js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte'], true)) {
            $contents = $this->maskPattern(
                $contents,
                '~([\\'\"`])(?:\\\\.|(?!\\1)[\\s\\S])*\\1(*SKIP)(*F)|//[^\\r\\n]*|/\\*[\\s\\S]*?\\*/~',
            );
        }
""",
    """        if (in_array($extension, ['js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte'], true)) {
            $contents = $this->maskJavaScriptNonCode($contents);
        }
""",
)

insert_before(
    "src/Scanners/TextEnvironmentScanner.php",
    """    private function maskPattern(string $contents, string $pattern): string
""",
    """    private function maskJavaScriptNonCode(string $contents): string
    {
        $result = '';
        $length = strlen($contents);
        $mode = 'code';
        $expressionDepths = [];

        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];
            $next = $contents[$index + 1] ?? null;

            if ($mode === 'line-comment') {
                if ($character === "\\n" || $character === "\\r") {
                    $result .= $character;
                    $mode = 'code';
                } else {
                    $result .= ' ';
                }

                continue;
            }

            if ($mode === 'block-comment') {
                if ($character === '*' && $next === '/') {
                    $result .= '  ';
                    $index++;
                    $mode = 'code';
                } else {
                    $result .= $this->maskedCharacter($character);
                }

                continue;
            }

            if ($mode === 'single-quote' || $mode === 'double-quote') {
                $result .= $this->maskedCharacter($character);

                if ($character === '\\\\' && $next !== null) {
                    $index++;
                    $result .= $this->maskedCharacter($next);

                    continue;
                }

                if (($mode === 'single-quote' && $character === "'")
                    || ($mode === 'double-quote' && $character === '\"')) {
                    $mode = 'code';
                }

                continue;
            }

            if ($mode === 'template') {
                if ($character === '\\\\' && $next !== null) {
                    $result .= $this->maskedCharacter($character);
                    $index++;
                    $result .= $this->maskedCharacter($next);

                    continue;
                }

                if ($character === '`') {
                    $result .= ' ';
                    $mode = 'code';

                    continue;
                }

                if ($character === '$' && $next === '{') {
                    $result .= '  ';
                    $index++;
                    $expressionDepths[] = 1;
                    $mode = 'code';

                    continue;
                }

                $result .= $this->maskedCharacter($character);

                continue;
            }

            if ($expressionDepths !== [] && $character === '{') {
                $last = array_key_last($expressionDepths);
                $expressionDepths[$last]++;
                $result .= $character;

                continue;
            }

            if ($expressionDepths !== [] && $character === '}') {
                $last = array_key_last($expressionDepths);
                $expressionDepths[$last]--;

                if ($expressionDepths[$last] === 0) {
                    array_pop($expressionDepths);
                    $result .= ' ';
                    $mode = 'template';
                } else {
                    $result .= $character;
                }

                continue;
            }

            if ($character === '/' && $next === '/') {
                $result .= '  ';
                $index++;
                $mode = 'line-comment';

                continue;
            }

            if ($character === '/' && $next === '*') {
                $result .= '  ';
                $index++;
                $mode = 'block-comment';

                continue;
            }

            if ($character === "'") {
                $result .= ' ';
                $mode = 'single-quote';

                continue;
            }

            if ($character === '\"') {
                $result .= ' ';
                $mode = 'double-quote';

                continue;
            }

            if ($character === '`') {
                $result .= ' ';
                $mode = 'template';

                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private function maskedCharacter(string $character): string
    {
        return $character === "\\n" || $character === "\\r" ? $character : ' ';
    }

""",
)

# Find a literal key argument even when named arguments are reordered.
replace_once(
    "src/Scanners/PhpEnvironmentScanner.php",
    """    /** @param array<int, array<int, mixed>|string> $tokens */
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
""",
    """    /** @param array<int, array<int, mixed>|string> $tokens */
    private function environmentKeyArgumentIndex(array $tokens, int $openIndex): ?int
    {
        $argumentIndex = $this->nextSignificantIndex($tokens, $openIndex);
        $firstPositional = null;

        while ($argumentIndex !== null && ($tokens[$argumentIndex] ?? null) !== ')') {
            $token = $tokens[$argumentIndex] ?? null;
            $name = null;
            $valueIndex = $argumentIndex;

            if (is_array($token) && $token[0] === T_STRING) {
                $colonIndex = $this->nextSignificantIndex($tokens, $argumentIndex);

                if ($colonIndex !== null && ($tokens[$colonIndex] ?? null) === ':') {
                    $name = strtolower($token[1]);
                    $valueIndex = $this->nextSignificantIndex($tokens, $colonIndex);

                    if ($valueIndex === null) {
                        return null;
                    }
                }
            }

            if ($name === 'key') {
                return $valueIndex;
            }

            if ($name === null && $firstPositional === null) {
                $firstPositional = $valueIndex;
            }

            $argumentIndex = $this->nextArgumentStart($tokens, $valueIndex);
        }

        return $firstPositional;
    }

    /** @param array<int, array<int, mixed>|string> $tokens */
    private function nextArgumentStart(array $tokens, int $index): ?int
    {
        $parentheses = 0;
        $brackets = 0;
        $braces = 0;

        for ($position = $index, $count = count($tokens); $position < $count; $position++) {
            $token = $tokens[$position];

            if (is_array($token)) {
                continue;
            }

            if ($token === '(') {
                $parentheses++;

                continue;
            }

            if ($token === ')') {
                if ($parentheses === 0 && $brackets === 0 && $braces === 0) {
                    return null;
                }

                $parentheses = max(0, $parentheses - 1);

                continue;
            }

            if ($token === '[') {
                $brackets++;

                continue;
            }

            if ($token === ']') {
                $brackets = max(0, $brackets - 1);

                continue;
            }

            if ($token === '{') {
                $braces++;

                continue;
            }

            if ($token === '}') {
                $braces = max(0, $braces - 1);

                continue;
            }

            if ($token === ',' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                return $this->nextSignificantIndex($tokens, $position);
            }
        }

        return null;
    }
""",
)

# Dockerfile is a first-class explicit infrastructure file.
replace_once(
    "config/env-guard.php",
    """        'public/index.php',
        'vite.config.js',
""",
    """        'public/index.php',
        'Dockerfile',
        'vite.config.js',
""",
)

# Feature regressions.
insert_before(
    "tests/Feature/EnvGuardTest.php",
    """it('reuses cached findings until relevant file metadata changes', function () {
""",
    """it('requires used keys to be documented by reference files even when a comparison file declares them', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\\nCOMPARISON_ONLY=testing\\n");
    file_put_contents($this->root.'/config/services.php', "<?php return ['value' => env('COMPARISON_ONLY')];\\n");

    $result = buildEnvGuard($this->root)->inspect();

    expect(findingCodesFor($result['findings'], 'COMPARISON_ONLY'))
        ->toContain('used-but-undeclared', 'missing-from-active')
        ->not->toContain('missing-from-environment-file');
});

it('does not treat discovered environment files as reference documentation', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\\n");
    file_put_contents($this->root.'/.env.production', "DISCOVERED_ONLY=production\\n");
    file_put_contents($this->root.'/config/services.php', "<?php return ['value' => env('DISCOVERED_ONLY')];\\n");

    $result = buildEnvGuard($this->root, ['discover_environment_files' => true])->inspect();

    expect(findingCodesFor($result['findings'], 'DISCOVERED_ONLY'))
        ->toContain('used-but-undeclared', 'missing-from-active');
});

it('scans explicitly configured extensionless project files', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\\n");
    file_put_contents($this->root.'/config/app.php', "<?php return [];\\n");
    file_put_contents($this->root.'/Dockerfile', <<<'DOCKER'
ARG APP_NAME
RUN echo "${APP_NAME}"
DOCKER);

    $result = buildEnvGuard($this->root, ['project_files' => ['Dockerfile']])->inspect();

    expect(findingCodesFor($result['findings'], 'APP_NAME'))->not->toContain('possibly-unused-key');
});

it('treats PHPUnit server entries as testing environment values', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\\nSERVER_ONLY=\\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\\n");
    file_put_contents($this->root.'/config/services.php', "<?php return ['value' => env('SERVER_ONLY')];\\n");
    file_put_contents($this->root.'/phpunit.xml', '<php><server name="SERVER_ONLY" value="testing"/></php>');

    $result = buildEnvGuard($this->root, ['project_files' => ['phpunit.xml']])->inspect();

    expect(findingCodesFor($result['findings'], 'SERVER_ONLY'))
        ->not->toContain('missing-from-environment-file')
        ->toContain('missing-from-active');
});

""",
)

insert_before(
    "tests/Feature/EnvGuardTest.php",
    """it('invalidates cached findings when behavior configuration changes', function () {
""",
    """it('invalidates cached findings when comparison runtime presence changes', function () {
    file_put_contents($this->root.'/.env', "APP_NAME=Codegenie\\n");
    file_put_contents($this->root.'/.env.example', "APP_NAME=\\n");
    file_put_contents($this->root.'/.env.testing', "APP_NAME=\\nENV_GUARD_COMPARISON_TOKEN=testing\\n");
    file_put_contents($this->root.'/config/services.php', "<?php return ['value' => env('ENV_GUARD_COMPARISON_TOKEN')];\\n");
    putenv('ENV_GUARD_COMPARISON_TOKEN=runtime-secret');

    try {
        $guard = buildEnvGuard($this->root);
        $first = $guard->inspect();

        putenv('ENV_GUARD_COMPARISON_TOKEN');
        $second = $guard->inspect();

        expect($first['fresh'])->toBeTrue()
            ->and(findingCodesFor($first['findings'], 'ENV_GUARD_COMPARISON_TOKEN'))->not->toContain('missing-from-active')
            ->and($second['fresh'])->toBeTrue()
            ->and(findingCodesFor($second['findings'], 'ENV_GUARD_COMPARISON_TOKEN'))->toContain('missing-from-active')
            ->and(json_encode($second['findings']))->not->toContain('runtime-secret');
    } finally {
        putenv('ENV_GUARD_COMPARISON_TOKEN');
    }
});

""",
)

# PHP scanner regressions.
insert_before(
    "tests/Unit/PhpEnvironmentScannerTest.php",
    """it('detects facade and raw environment access without scanning comments or strings', function () {
""",
    """it('finds key named arguments after other named arguments', function () {
    file_put_contents($this->root.'/config/services.php', <<<'PHPFILE'
<?php
return ['token' => env(default: fn () => 'fallback', key: 'REORDERED_CONFIG')];
PHPFILE);
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
use Illuminate\\Support\\Env as LaravelEnv;

$one = LaravelEnv::get(default: ['nested' => true], key: 'REORDERED_FACADE');
$dynamic = env(default: null);
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/config/services.php',
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect(array_column($result['usages'], 'key'))->toBe(['REORDERED_CONFIG', 'REORDERED_FACADE'])
        ->and($result['usages'][0]['in_config'])->toBeTrue()
        ->and($result['usages'][1]['in_config'])->toBeFalse()
        ->and($result['dynamic'])->toHaveCount(1);
});

""",
)

# Text scanner regressions.
insert_before(
    "tests/Unit/TextEnvironmentScannerTest.php",
    """it('detects Vite loadEnv access', function () {
""",
    """it('ignores JavaScript strings and template text while scanning template expressions', function () {
    $path = tempnam(sys_get_temp_dir(), 'vite-').'.ts';
    file_put_contents($path, <<<'JS'
const doubleQuoted = "import.meta.env.VITE_STRING";
const singleQuoted = 'process.env.NODE_STRING';
const templateText = `import.meta.env.VITE_TEMPLATE_TEXT`;
const actual = `${import.meta.env.VITE_REAL}`;
const nested = `${flag ? `inner ${import.meta.env.VITE_NESTED}` : process.env.NODE_REAL}`;
JS);

    $result = (new TextEnvironmentScanner)->scan([$path], ['NODE_STRING', 'NODE_REAL']);
    $keys = array_column($result['usages'], 'key');

    expect($keys)->toContain('VITE_REAL', 'VITE_NESTED', 'NODE_REAL')
        ->and($keys)->not->toContain('VITE_STRING', 'VITE_TEMPLATE_TEXT', 'NODE_STRING');

    @unlink($path);
});

it('detects PHPUnit env and server entries', function () {
    $path = tempnam(sys_get_temp_dir(), 'phpunit-');
    file_put_contents($path, '<php><env name="ENV_KEY" value="1"/><server name="SERVER_KEY" value="2"/><!-- <server name="COMMENTED"/> --></php>');

    $result = (new TextEnvironmentScanner)->scan([$path], []);

    expect($result['phpunit_keys'])->toBe(['ENV_KEY', 'SERVER_KEY']);

    @unlink($path);
});

""",
)

# Documentation and default configuration examples.
replace_once(
    "README.md",
    """        'public/index.php',
        'vite.config.js',
""",
    """        'public/index.php',
        'Dockerfile',
        'vite.config.js',
""",
)

replace_once(
    "README.md",
    """<php>
    <env name=\"CACHE_STORE\" value=\"array\"/>
</php>
""",
    """<php>
    <env name=\"CACHE_STORE\" value=\"array\"/>
    <server name=\"QUEUE_CONNECTION\" value=\"sync\"/>
</php>
""",
)

replace_once(
    "README.md",
    "When `CACHE_STORE` is absent from `.env.testing` but present in `phpunit.xml`, the guard treats it as supplied for the testing environment.",
    "When a key is absent from `.env.testing` but present in a PHPUnit `<env>` or `<server>` entry, the guard treats it as supplied for the testing environment.",
)

insert_before(
    "README.md",
    """## Commented example keys
""",
    """A key used by application-owned source must still be documented in a configured reference file such as `.env.example`. Merely defining it in `.env.testing`, a discovered `.env.*` file, or another comparison file does not document the deployment contract.

""",
)

replace_once(
    "docs/scenarios.md",
    """- a key present in `.env` but not documented in `.env.example` is reported;
""",
    """- a key present in `.env` but not documented in `.env.example` is reported;
- a key used by application-owned source must be documented by a reference file even when a comparison or discovered env file declares it;
""",
)

replace_once(
    "docs/scenarios.md",
    "`phpunit.xml` can also define environment values. When a project key is absent from `.env.testing` but is supplied through `<env name=\"...\">`, the guard does not report it as missing from the testing environment file.",
    "`phpunit.xml` can also define environment values. When a project key is absent from `.env.testing` but is supplied through `<env name=\"...\">` or `<server name=\"...\">`, the guard does not report it as missing from the testing environment file.",
)

replace_once(
    "docs/scenarios.md",
    """It also recognizes `VITE_*` values accessed after Vite's `loadEnv()` helper and declared `process.env.KEY` references. Built-in Vite values such as `MODE`, `DEV`, `PROD`, `SSR`, and `BASE_URL` are not treated as missing project keys.
""",
    """It also recognizes `VITE_*` values accessed after Vite's `loadEnv()` helper and declared `process.env.KEY` references. Built-in Vite values such as `MODE`, `DEV`, `PROD`, `SSR`, and `BASE_URL` are not treated as missing project keys. JavaScript strings and plain template-literal text are ignored, while executable `${...}` template expressions remain auditable.
""",
)

replace_once(
    "docs/scenarios.md",
    "Source analysis is limited to application-owned paths, configured project files and a configurable maximum file size.",
    "Source analysis is limited to application-owned paths, configured project files and a configurable maximum file size. Explicit `project_files` entries are treated as text even when they are extensionless, which supports files such as `Dockerfile`.",
)

replace_once(
    "CHANGELOG.md",
    "- PHP environment scanning now recognizes literal `key:` named arguments for `env()` and `Env::get()`.",
    """- PHP environment scanning now recognizes literal `key:` named arguments for `env()` and `Env::get()`.
- Reference documentation is now evaluated independently from active, comparison, and discovered env files, preventing test-only declarations from hiding missing `.env.example` entries.
- Missing-active checks now include application-used keys, including documented optional keys that become required through actual usage.
- JavaScript analysis now masks quoted strings and non-executable template text while preserving `${...}` expressions.
- PHPUnit `<server>` entries and explicitly configured extensionless files such as `Dockerfile` are now audited.
- Runtime-presence cache invalidation now covers keys declared by comparison files as well as reference files.""",
)
