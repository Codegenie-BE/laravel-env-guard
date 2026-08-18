<?php

namespace Codegenie\EnvGuard\Scanners;

final class TextEnvironmentScanner
{
    /**
     * @param  list<string>  $files
     * @param  list<string>  $declaredKeys
     * @return array{
     *     usages:list<array{key:string, path:string, line:int, source:string}>,
     *     blade_env:list<array{key:string, path:string, line:int}>,
     *     phpunit_keys:list<string>
     * }
     */
    public function scan(array $files, array $declaredKeys): array
    {
        $result = [
            'usages' => [],
            'blade_env' => [],
            'phpunit_keys' => [],
        ];

        $declaredLookup = array_fill_keys($declaredKeys, true);

        foreach ($files as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $contents = $this->maskComments($contents, $file);
            $basename = basename($file);

            if ($basename === 'phpunit.xml' || $basename === 'phpunit.xml.dist') {
                if (preg_match_all('/<(?:env|server)\s+[^>]*name\s*=\s*([\'\"])([^\'\"]+)\1/i', $contents, $matches)) {
                    $result['phpunit_keys'] = array_values(array_unique(array_merge($result['phpunit_keys'], $matches[2])));
                }
            }

            if ($this->isFrontendSourceFile($file, $contents)) {
                $this->collectFrontendUsages($result, $contents, $file, $declaredLookup);
            }

            if ($declaredLookup !== [] && $this->isInfrastructureFile($file) && ! $this->isJavaScriptFile($file)) {
                $this->collectInfrastructureUsages($result['usages'], $contents, $file, $declaredLookup);
            }
        }

        return $result;
    }

    /**
     * @param  array{
     *     usages:list<array{key:string, path:string, line:int, source:string}>,
     *     blade_env:list<array{key:string, path:string, line:int}>,
     *     phpunit_keys:list<string>
     * }  $result
     * @param  array<string, true>  $declaredLookup
     */
    private function collectFrontendUsages(array &$result, string $contents, string $file, array $declaredLookup): void
    {
        $codeContents = $this->maskJavaScriptNonCode($contents);
        $loadEnvVariables = [];

        if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*loadEnv\s*\(/', $codeContents, $matches)) {
            $loadEnvVariables = array_values(array_unique($matches[1]));
        }

        $bracketContents = $this->maskJavaScriptNonCode(
            $contents,
            ['import.meta.env', 'process.env', ...$loadEnvVariables],
        );
        $viteFilter = static fn (string $key): bool => str_starts_with($key, 'VITE_') || isset($declaredLookup[$key]);
        $declaredFilter = static fn (string $key): bool => isset($declaredLookup[$key]);

        $this->collectPattern($result['usages'], $codeContents, $file, '/\bimport\.meta\.env\.([A-Za-z_$][A-Za-z0-9_$]*)/', 'vite', $viteFilter);
        $this->collectPattern($result['usages'], $bracketContents, $file, '/\bimport\.meta\.env\s*\[\s*[\'\"]([A-Za-z0-9_.]+)[\'\"]\s*\]/', 'vite', $viteFilter);
        $this->collectPattern($result['usages'], $codeContents, $file, '/\bprocess\.env\.([A-Za-z_$][A-Za-z0-9_$]*)/', 'node', $declaredFilter);
        $this->collectPattern($result['usages'], $bracketContents, $file, '/\bprocess\.env\s*\[\s*[\'\"]([A-Za-z0-9_.]+)[\'\"]\s*\]/', 'node', $declaredFilter);

        $this->collectDestructuredObject(
            $result['usages'],
            $codeContents,
            $file,
            'import\.meta\.env(?![A-Za-z0-9_$])',
            'vite',
            $viteFilter,
        );
        $this->collectDestructuredObject(
            $result['usages'],
            $codeContents,
            $file,
            'process\.env(?![A-Za-z0-9_$])',
            'node',
            $declaredFilter,
        );

        foreach ($loadEnvVariables as $variable) {
            $variablePattern = '(?<![A-Za-z0-9_$\.])'.preg_quote($variable, '/').'(?![A-Za-z0-9_$])';

            $this->collectPattern(
                $result['usages'],
                $codeContents,
                $file,
                '/'.$variablePattern.'\s*\.\s*([A-Za-z_$][A-Za-z0-9_$]*)/',
                'vite-load-env',
                $viteFilter,
            );
            $this->collectPattern(
                $result['usages'],
                $bracketContents,
                $file,
                '/'.$variablePattern.'\s*\[\s*[\'\"]([A-Za-z0-9_.]+)[\'\"]\s*\]/',
                'vite-load-env',
                $viteFilter,
            );
            $this->collectDestructuredObject(
                $result['usages'],
                $codeContents,
                $file,
                $variablePattern,
                'vite-load-env',
                $viteFilter,
            );
        }

        $this->collectDirectDestructuredLoadEnv(
            $result['usages'],
            $codeContents,
            $file,
            $viteFilter,
        );

        if (str_ends_with($file, '.blade.php') && preg_match_all('/(?<![A-Za-z0-9_:>])(?:\\\\)?env\(\s*(?:key\s*:\s*)?([\'\"])([^\'\"]+)\1/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as [$key, $offset]) {
                $result['blade_env'][] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                ];
            }
        }
    }

    private function maskComments(string $contents, string $file): string
    {
        $contents = $this->maskPattern($contents, '/<!--.*?-->/s');
        $contents = $this->maskPattern($contents, '/<!\[CDATA\[.*?\]\]>/s');

        if (str_ends_with($file, '.blade.php')) {
            $contents = $this->maskPattern($contents, '/\{\{--.*?--\}\}/s');
        }

        if ($this->isInfrastructureFile($file) && ! $this->isJavaScriptFile($file)) {
            $contents = $this->maskInfrastructureComments($contents);
        }

        return $contents;
    }

    /** @param list<string> $bracketOwners */
    private function maskJavaScriptNonCode(string $contents, array $bracketOwners = []): string
    {
        $masked = $contents;
        $length = strlen($contents);
        /** @var list<array{mode:string, template_depth:?int, quote:?string, preserve:bool, character_class:bool}> $stack */
        $stack = [
            [
                'mode' => 'code',
                'template_depth' => null,
                'quote' => null,
                'preserve' => false,
                'character_class' => false,
            ],
        ];

        for ($index = 0; $index < $length; $index++) {
            $stateIndex = count($stack) - 1;
            $state = $stack[$stateIndex];
            $character = $contents[$index];
            $next = $index + 1 < $length ? $contents[$index + 1] : null;

            if ($state['mode'] === 'line-comment') {
                if ($character === "\n" || $character === "\r") {
                    array_pop($stack);
                } else {
                    $this->maskByte($masked, $index);
                }

                continue;
            }

            if ($state['mode'] === 'block-comment') {
                if ($character === '*' && $next === '/') {
                    $this->maskByte($masked, $index);
                    $this->maskByte($masked, $index + 1);
                    $index++;
                    array_pop($stack);
                } else {
                    $this->maskByte($masked, $index);
                }

                continue;
            }

            if ($state['mode'] === 'string') {
                if (! $state['preserve']) {
                    $this->maskByte($masked, $index);
                }

                if ($character === '\\' && $next !== null) {
                    if (! $state['preserve']) {
                        $this->maskByte($masked, $index + 1);
                    }

                    $index++;

                    continue;
                }

                if ($character === $state['quote']) {
                    array_pop($stack);
                }

                continue;
            }

            if ($state['mode'] === 'template') {
                $this->maskByte($masked, $index);

                if ($character === '\\' && $next !== null) {
                    $this->maskByte($masked, $index + 1);
                    $index++;

                    continue;
                }

                if ($character === '`') {
                    array_pop($stack);

                    continue;
                }

                if ($character === '$' && $next === '{') {
                    $this->maskByte($masked, $index + 1);
                    $index++;
                    $stack[] = [
                        'mode' => 'code',
                        'template_depth' => 1,
                        'quote' => null,
                        'preserve' => false,
                        'character_class' => false,
                    ];
                }

                continue;
            }

            if ($state['mode'] === 'regex') {
                $this->maskByte($masked, $index);

                if ($character === '\\' && $next !== null) {
                    $this->maskByte($masked, $index + 1);
                    $index++;

                    continue;
                }

                if ($character === '[') {
                    $stack[$stateIndex]['character_class'] = true;

                    continue;
                }

                if ($character === ']') {
                    $stack[$stateIndex]['character_class'] = false;

                    continue;
                }

                if ($character === '/' && ! $state['character_class']) {
                    array_pop($stack);
                }

                continue;
            }

            if ($character === '/' && $next === '/') {
                $this->maskByte($masked, $index);
                $this->maskByte($masked, $index + 1);
                $index++;
                $stack[] = [
                    'mode' => 'line-comment',
                    'template_depth' => null,
                    'quote' => null,
                    'preserve' => false,
                    'character_class' => false,
                ];

                continue;
            }

            if ($character === '/' && $next === '*') {
                $this->maskByte($masked, $index);
                $this->maskByte($masked, $index + 1);
                $index++;
                $stack[] = [
                    'mode' => 'block-comment',
                    'template_depth' => null,
                    'quote' => null,
                    'preserve' => false,
                    'character_class' => false,
                ];

                continue;
            }

            if ($character === '/' && $this->startsJavaScriptRegex($contents, $index)) {
                $this->maskByte($masked, $index);
                $stack[] = [
                    'mode' => 'regex',
                    'template_depth' => null,
                    'quote' => null,
                    'preserve' => false,
                    'character_class' => false,
                ];

                continue;
            }

            if ($character === "'" || $character === '"') {
                $preserve = $this->isBracketPropertyString($contents, $index, $bracketOwners);

                if (! $preserve) {
                    $this->maskByte($masked, $index);
                }

                $stack[] = [
                    'mode' => 'string',
                    'template_depth' => null,
                    'quote' => $character,
                    'preserve' => $preserve,
                    'character_class' => false,
                ];

                continue;
            }

            if ($character === '`') {
                $this->maskByte($masked, $index);
                $stack[] = [
                    'mode' => 'template',
                    'template_depth' => null,
                    'quote' => null,
                    'preserve' => false,
                    'character_class' => false,
                ];

                continue;
            }

            $templateDepth = $state['template_depth'];

            if ($templateDepth !== null) {
                if ($character === '{') {
                    $stack[$stateIndex]['template_depth'] = $templateDepth + 1;
                } elseif ($character === '}') {
                    $templateDepth--;
                    $stack[$stateIndex]['template_depth'] = $templateDepth;

                    if ($templateDepth === 0) {
                        $this->maskByte($masked, $index);
                        array_pop($stack);
                    }
                }
            }
        }

        return $masked;
    }

    private function startsJavaScriptRegex(string $contents, int $slashIndex): bool
    {
        $prefix = rtrim(substr($contents, 0, $slashIndex));

        if ($prefix === '') {
            return true;
        }

        $previous = $prefix[strlen($prefix) - 1];

        if (str_contains('([{:;,=!?&|+-*%^~<>', $previous)) {
            return true;
        }

        return preg_match('/(?:^|[^A-Za-z0-9_$])(?:return|case|throw|else|do|typeof|instanceof|in|of|yield|await|void|delete|new)$/', $prefix) === 1;
    }

    /** @param list<string> $owners */
    private function isBracketPropertyString(string $contents, int $quoteIndex, array $owners): bool
    {
        $bracketIndex = null;

        for ($index = $quoteIndex - 1; $index >= 0; $index--) {
            if (ctype_space($contents[$index])) {
                continue;
            }

            if ($contents[$index] !== '[') {
                return false;
            }

            $bracketIndex = $index;
            break;
        }

        if ($bracketIndex === null) {
            return false;
        }

        $prefix = rtrim(substr($contents, 0, $bracketIndex));

        foreach ($owners as $owner) {
            if ($owner === '') {
                continue;
            }

            $length = strlen($owner);

            if (! str_ends_with($prefix, $owner)) {
                continue;
            }

            $boundary = strlen($prefix) - $length - 1;

            if ($boundary < 0 || preg_match('/[A-Za-z0-9_$\.]/', $prefix[$boundary]) !== 1) {
                return true;
            }
        }

        return false;
    }

    private function maskByte(string &$contents, int $index): void
    {
        if (! isset($contents[$index]) || $contents[$index] === "\n" || $contents[$index] === "\r") {
            return;
        }

        $contents[$index] = ' ';
    }

    private function maskInfrastructureComments(string $contents): string
    {
        $masked = $contents;
        $quote = null;
        $length = strlen($contents);

        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];

            if ($quote !== null) {
                if ($character === '\\' && $quote === '"' && $index + 1 < $length) {
                    $index++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character !== '#') {
                continue;
            }

            while ($index < $length && $contents[$index] !== "\n" && $contents[$index] !== "\r") {
                $this->maskByte($masked, $index);
                $index++;
            }

            $index--;
        }

        return $masked;
    }

    private function maskPattern(string $contents, string $pattern): string
    {
        return preg_replace_callback(
            $pattern,
            static fn (array $match): string => preg_replace('/[^\r\n]/', ' ', $match[0]) ?? $match[0],
            $contents,
        ) ?? $contents;
    }

    /** @param list<array{key:string, path:string, line:int, source:string}> $target */
    private function collectPattern(
        array &$target,
        string $contents,
        string $file,
        string $pattern,
        string $source,
        ?callable $filter = null,
    ): void {
        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as [$key, $offset]) {
            if ($filter !== null && ! $filter($key)) {
                continue;
            }

            $target[] = [
                'key' => $key,
                'path' => $file,
                'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                'source' => $source,
            ];
        }
    }

    /**
     * @param  list<array{key:string, path:string, line:int, source:string}>  $target
     * @param  callable(string): bool  $filter
     */
    private function collectDestructuredObject(
        array &$target,
        string $contents,
        string $file,
        string $objectPattern,
        string $source,
        callable $filter,
    ): void {
        $pattern = '/\b(?:const|let|var)\s*\{([^}]*)\}\s*=\s*'.$objectPattern.'/';

        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as [$members, $membersOffset]) {
            if (! preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\b/', $members, $keys, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($keys[1] as [$key, $offset]) {
                if (! $filter($key)) {
                    continue;
                }

                $target[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $membersOffset + $offset), "\n") + 1,
                    'source' => $source,
                ];
            }
        }
    }

    /**
     * @param  list<array{key:string, path:string, line:int, source:string}>  $target
     * @param  callable(string): bool  $filter
     */
    private function collectDirectDestructuredLoadEnv(
        array &$target,
        string $contents,
        string $file,
        callable $filter,
    ): void {
        $pattern = '/\b(?:const|let|var)\s*\{([^}]*)\}\s*=\s*loadEnv\s*\(/';

        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as [$members, $membersOffset]) {
            if (! preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\b/', $members, $keys, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($keys[1] as [$key, $offset]) {
                if (! $filter($key)) {
                    continue;
                }

                $target[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $membersOffset + $offset), "\n") + 1,
                    'source' => 'vite-load-env',
                ];
            }
        }
    }

    /**
     * @param  list<array{key:string, path:string, line:int, source:string}>  $target
     * @param  array<string, true>  $declaredLookup
     */
    private function collectInfrastructureUsages(array &$target, string $contents, string $file, array $declaredLookup): void
    {
        $patterns = [
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(?::?[-+?])[^}]*)?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
            '/\$\{\{\s*env\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as $match) {
                $key = ($match[1][0] ?? '') !== '' ? $match[1][0] : ($match[2][0] ?? '');
                $offset = ($match[1][0] ?? '') !== '' ? $match[1][1] : ($match[2][1] ?? 0);

                if ($key === '' || ! isset($declaredLookup[$key])) {
                    continue;
                }

                $target[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                    'source' => 'project',
                ];
            }
        }
    }

    private function isFrontendSourceFile(string $file, string $contents): bool
    {
        return $this->isJavaScriptFile($file)
            || str_ends_with($file, '.blade.php')
            || preg_match('/\b(?:import\.meta\.env|process\.env|loadEnv\s*\()/', $contents) === 1;
    }

    private function isJavaScriptFile(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), [
            'js',
            'jsx',
            'mjs',
            'cjs',
            'ts',
            'tsx',
            'mts',
            'cts',
            'vue',
            'svelte',
        ], true);
    }

    private function isInfrastructureFile(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        $basename = basename($normalized);

        return str_contains($normalized, '/.github/workflows/')
            || str_contains($normalized, '/scripts/')
            || str_contains($normalized, '/bin/')
            || str_starts_with($basename, 'Dockerfile')
            || in_array($basename, [
                'compose.yaml',
                'compose.yml',
                'docker-compose.yaml',
                'docker-compose.yml',
            ], true);
    }
}
