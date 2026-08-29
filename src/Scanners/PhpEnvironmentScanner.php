<?php

namespace Codegenie\EnvGuard\Scanners;

final class PhpEnvironmentScanner
{
    /**
     * @param  list<string>  $files
     * @return array{
     *     usages:list<array{key:string, path:string, line:int, in_config:bool, source:string}>,
     *     dynamic:list<array{path:string, line:int, in_config:bool, source:string}>,
     *     raw:list<array{key:string, path:string, line:int, source:string}>
     * }
     */
    public function scan(array $files, string $configPath): array
    {
        $result = [
            'usages' => [],
            'dynamic' => [],
            'raw' => [],
        ];

        foreach ($files as $file) {
            if (! is_readable($file) || str_ends_with($file, '.blade.php')) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $inConfig = $this->isWithin($file, $configPath);
            $tokens = token_get_all($contents);
            $helpers = $this->scanEnvHelpers($tokens, $file, $inConfig);
            $result['usages'] = array_merge($result['usages'], $helpers['usages']);
            $result['dynamic'] = array_merge($result['dynamic'], $helpers['dynamic']);

            $facade = $this->scanEnvFacade($tokens, $file, $inConfig);
            $result['usages'] = array_merge($result['usages'], $facade['usages']);
            $result['dynamic'] = array_merge($result['dynamic'], $facade['dynamic']);
            $result['raw'] = array_merge($result['raw'], $this->scanRawAccess($tokens, $file));
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return array{
     *     usages:list<array{key:string, path:string, line:int, in_config:bool, source:string}>,
     *     dynamic:list<array{path:string, line:int, in_config:bool, source:string}>
     * }
     */
    private function scanEnvHelpers(array $tokens, string $file, bool $inConfig): array
    {
        $result = [
            'usages' => [],
            'dynamic' => [],
        ];
        $aliases = $this->envHelperAliases($tokens);

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            $isEnvHelper = ($token[0] === T_STRING && strtolower($token[1]) === 'env')
                || ($token[0] === T_NAME_FULLY_QUALIFIED && strtolower($token[1]) === '\\env')
                || ($token[0] === T_STRING && isset($aliases[strtolower($token[1])]));

            if (! $isEnvHelper) {
                continue;
            }

            $previous = $this->previousSignificant($tokens, $index);

            if ($token[0] === T_STRING && is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $openIndex = $this->nextSignificantIndex($tokens, $index);

            if ($openIndex === null || $tokens[$openIndex] !== '(') {
                continue;
            }

            $this->recordEnvironmentCall($result, $tokens, $openIndex, $file, $token[2], $inConfig, 'env');
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return array<string, true>
     */
    private function envHelperAliases(array $tokens): array
    {
        $aliases = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! $this->tokenIs($tokens[$index], T_USE)) {
                continue;
            }

            $functionIndex = $this->nextSignificantIndex($tokens, $index);

            if ($functionIndex === null || ! $this->tokenIs($tokens[$functionIndex], T_FUNCTION)) {
                continue;
            }

            $start = $this->nextSignificantIndex($tokens, $functionIndex);

            if ($start === null) {
                continue;
            }

            $end = $this->statementEndIndex($tokens, $start);

            if ($end === null) {
                continue;
            }

            $statement = '';

            for ($position = $start; $position < $end; $position++) {
                $token = $tokens[$position];

                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $statement .= is_array($token) ? $token[1] : $token;
            }

            foreach ($this->splitTopLevel($statement, ',') as $import) {
                $this->collectEnvHelperImportAlias($aliases, $import);
            }

            $index = $end;
        }

        return $aliases;
    }

    /** @param array<string, true> $aliases */
    private function collectEnvHelperImportAlias(array &$aliases, string $import): void
    {
        if (! preg_match('/^\s*\\\\?([A-Za-z_][A-Za-z0-9_]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/i', $import, $matches)) {
            return;
        }

        if (strtolower($matches[1]) !== 'env') {
            return;
        }

        $alias = $matches[2] ?? 'env';
        $aliases[strtolower($alias)] = true;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return array{
     *     usages:list<array{key:string, path:string, line:int, in_config:bool, source:string}>,
     *     dynamic:list<array{path:string, line:int, in_config:bool, source:string}>
     * }
     */
    private function scanEnvFacade(array $tokens, string $file, bool $inConfig): array
    {
        $result = [
            'usages' => [],
            'dynamic' => [],
        ];
        $aliases = $this->envFacadeAliases($tokens);

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            $isFacade = $token[0] === T_NAME_FULLY_QUALIFIED
                && strtolower($token[1]) === '\\illuminate\\support\\env';

            if (! $isFacade && $token[0] === T_STRING && isset($aliases[strtolower($token[1])])) {
                $isFacade = true;
            }

            if (! $isFacade) {
                continue;
            }

            $doubleColonIndex = $this->nextSignificantIndex($tokens, $index);

            if ($doubleColonIndex === null || ! $this->tokenIs($tokens[$doubleColonIndex], T_DOUBLE_COLON)) {
                continue;
            }

            $methodIndex = $this->nextSignificantIndex($tokens, $doubleColonIndex);

            if ($methodIndex === null || ! is_array($tokens[$methodIndex]) || $tokens[$methodIndex][0] !== T_STRING || strtolower($tokens[$methodIndex][1]) !== 'get') {
                continue;
            }

            $openIndex = $this->nextSignificantIndex($tokens, $methodIndex);

            if ($openIndex === null || $tokens[$openIndex] !== '(') {
                continue;
            }

            $this->recordEnvironmentCall($result, $tokens, $openIndex, $file, $token[2], $inConfig, 'Env::get');
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return array<string, true>
     */
    private function envFacadeAliases(array $tokens): array
    {
        $aliases = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! $this->tokenIs($tokens[$index], T_USE)) {
                continue;
            }

            $start = $this->nextSignificantIndex($tokens, $index);

            if ($start === null || $tokens[$start] === '(' || $this->tokenIs($tokens[$start], T_FUNCTION) || $this->tokenIs($tokens[$start], T_CONST)) {
                continue;
            }

            $end = $this->statementEndIndex($tokens, $start);

            if ($end === null) {
                continue;
            }

            $statement = '';

            for ($position = $start; $position < $end; $position++) {
                $token = $tokens[$position];

                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $statement .= is_array($token) ? $token[1] : $token;
            }

            foreach ($this->splitTopLevel($statement, ',') as $import) {
                $this->collectEnvImportAlias($aliases, $import);
            }

            $index = $end;
        }

        return $aliases;
    }

    /** @param array<string, true> $aliases */
    private function collectEnvImportAlias(array &$aliases, string $import): void
    {
        $import = trim($import);

        if ($import === '') {
            return;
        }

        if (preg_match('/^(.*)\\\\\{(.*)\}$/s', $import, $group)) {
            $prefix = rtrim(trim($group[1]), '\\');

            foreach ($this->splitTopLevel($group[2], ',') as $member) {
                $this->collectDirectEnvImportAlias($aliases, $prefix.'\\'.trim($member));
            }

            return;
        }

        $this->collectDirectEnvImportAlias($aliases, $import);
    }

    /** @param array<string, true> $aliases */
    private function collectDirectEnvImportAlias(array &$aliases, string $import): void
    {
        if (! preg_match('/^\s*([\\\\A-Za-z0-9_]+?)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/i', $import, $matches)) {
            return;
        }

        $name = ltrim($matches[1], '\\');

        if (strtolower($name) !== 'illuminate\\support\\env') {
            return;
        }

        $alias = $matches[2] ?? 'Env';
        $aliases[strtolower($alias)] = true;
    }

    /**
     * @param  array{usages:list<array{key:string, path:string, line:int, in_config:bool, source:string}>, dynamic:list<array{path:string, line:int, in_config:bool, source:string}>}  $result
     * @param  array<int, array<int, mixed>|string>  $tokens
     */
    private function recordEnvironmentCall(
        array &$result,
        array $tokens,
        int $openIndex,
        string $file,
        int $line,
        bool $inConfig,
        string $source,
    ): void {
        $resolved = $this->resolveLiteralCallArgument($tokens, $openIndex, 'key');

        if ($resolved['status'] === 'callable') {
            return;
        }

        if ($resolved['status'] !== 'literal') {
            $result['dynamic'][] = [
                'path' => $file,
                'line' => $line,
                'in_config' => $inConfig,
                'source' => $source,
            ];

            return;
        }

        $result['usages'][] = [
            'key' => $resolved['key'],
            'path' => $file,
            'line' => $line,
            'in_config' => $inConfig,
            'source' => $source,
        ];
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return list<array{key:string, path:string, line:int, source:string}>
     */
    private function scanRawAccess(array $tokens, string $file): array
    {
        $usages = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            $isGetenv = ($token[0] === T_STRING && strtolower($token[1]) === 'getenv')
                || ($token[0] === T_NAME_FULLY_QUALIFIED && strtolower($token[1]) === '\\getenv');

            if ($isGetenv) {
                $previous = $this->previousSignificant($tokens, $index);

                if ($token[0] === T_STRING && is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    continue;
                }

                $openIndex = $this->nextSignificantIndex($tokens, $index);

                if ($openIndex === null || $tokens[$openIndex] !== '(') {
                    continue;
                }

                $resolved = $this->resolveLiteralCallArgument($tokens, $openIndex, 'name');

                if ($resolved['status'] === 'literal') {
                    $usages[] = [
                        'key' => $resolved['key'],
                        'path' => $file,
                        'line' => $token[2],
                        'source' => 'getenv',
                    ];
                }

                continue;
            }

            if ($token[0] !== T_VARIABLE || ! in_array($token[1], ['$_ENV', '$_SERVER'], true)) {
                continue;
            }

            $openIndex = $this->nextSignificantIndex($tokens, $index);
            $keyIndex = $openIndex === null ? null : $this->nextSignificantIndex($tokens, $openIndex);
            $closeIndex = $keyIndex === null ? null : $this->nextSignificantIndex($tokens, $keyIndex);

            if ($openIndex === null || $tokens[$openIndex] !== '[' || $keyIndex === null || $closeIndex === null || $tokens[$closeIndex] !== ']') {
                continue;
            }

            $key = $this->literalKeyAt($tokens, $keyIndex);

            if ($key === null) {
                continue;
            }

            $usages[] = [
                'key' => $key,
                'path' => $file,
                'line' => $token[2],
                'source' => $token[1],
            ];
        }

        return $usages;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return array{status:'literal', key:string}|array{status:'dynamic'|'callable'}
     */
    private function resolveLiteralCallArgument(array $tokens, int $openIndex, string $parameterName): array
    {
        $ranges = $this->argumentRanges($tokens, $openIndex);

        if ($ranges === null || $ranges === []) {
            return ['status' => 'dynamic'];
        }

        if (count($ranges) === 1) {
            $only = $this->significantIndexes($tokens, $ranges[0][0], $ranges[0][1]);

            if (count($only) === 1 && $this->tokenIs($tokens[$only[0]], T_ELLIPSIS)) {
                return ['status' => 'callable'];
            }
        }

        $positional = null;
        $named = null;

        foreach ($ranges as $position => [$start, $end]) {
            $indexes = $this->significantIndexes($tokens, $start, $end);

            if ($indexes === []) {
                continue;
            }

            if (count($indexes) >= 3 && $tokens[$indexes[1]] === ':') {
                $labelToken = $tokens[$indexes[0]];
                $label = is_array($labelToken) ? strtolower($labelToken[1]) : strtolower($labelToken);

                if ($label === strtolower($parameterName)) {
                    $named = array_slice($indexes, 2);
                }

                continue;
            }

            if ($position === 0) {
                $positional = $indexes;
            }
        }

        $candidate = $named ?? $positional;

        if ($candidate === null || count($candidate) !== 1) {
            return ['status' => 'dynamic'];
        }

        $key = $this->literalKeyAt($tokens, $candidate[0]);

        return $key === null
            ? ['status' => 'dynamic']
            : ['status' => 'literal', 'key' => $key];
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return list<array{int, int}>|null
     */
    private function argumentRanges(array $tokens, int $openIndex): ?array
    {
        $ranges = [];
        $start = $openIndex + 1;
        $parentheses = 0;
        $brackets = 0;
        $braces = 0;
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === '(') {
                $parentheses++;

                continue;
            }

            if ($token === '[') {
                $brackets++;

                continue;
            }

            if ($token === '{') {
                $braces++;

                continue;
            }

            if ($token === ')' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                if ($this->significantIndexes($tokens, $start, $index - 1) !== []) {
                    $ranges[] = [$start, $index - 1];
                }

                return $ranges;
            }

            if ($token === ')') {
                $parentheses = max(0, $parentheses - 1);

                continue;
            }

            if ($token === ']') {
                $brackets = max(0, $brackets - 1);

                continue;
            }

            if ($token === '}') {
                $braces = max(0, $braces - 1);

                continue;
            }

            if ($token === ',' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                $ranges[] = [$start, $index - 1];
                $start = $index + 1;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return list<int>
     */
    private function significantIndexes(array $tokens, int $start, int $end): array
    {
        $indexes = [];

        for ($index = $start; $index <= $end; $index++) {
            $token = $tokens[$index] ?? null;

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token !== null) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /** @param array<int, array<int, mixed>|string> $tokens */
    private function literalKeyAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index] ?? null;

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $key = $this->decodeStringLiteral($token[1]);

        return $key === '' ? null : $key;
    }

    /** @param array<int, array<int, mixed>|string> $tokens */
    private function statementEndIndex(array $tokens, int $start): ?int
    {
        $braces = 0;

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === '{') {
                $braces++;
            } elseif ($tokens[$index] === '}') {
                $braces = max(0, $braces - 1);
            } elseif ($tokens[$index] === ';' && $braces === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $start = 0;
        $braces = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            if ($value[$index] === '{') {
                $braces++;
            } elseif ($value[$index] === '}') {
                $braces = max(0, $braces - 1);
            } elseif ($value[$index] === $separator && $braces === 0) {
                $parts[] = substr($value, $start, $index - $start);
                $start = $index + 1;
            }
        }

        $parts[] = substr($value, $start);

        return $parts;
    }

    /** @param array<int, array<int, mixed>|string> $tokens */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array<int, array<int, mixed>|string>  $tokens
     * @return array<int, mixed>|string|null
     */
    private function previousSignificant(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @param array<int, mixed>|string $token */
    private function tokenIs(array|string $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    private function decodeStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }

        $quote = $literal[0];
        $value = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        }

        if ($quote === '"') {
            return stripcslashes($value);
        }

        return null;
    }

    private function isWithin(string $file, string $directory): bool
    {
        $file = $this->normalizePath($file);
        $directory = rtrim($this->normalizePath($directory), '/').'/';

        return str_starts_with($file, $directory);
    }

    private function normalizePath(string $path): string
    {
        $resolved = realpath($path);
        $normalized = str_replace('\\', '/', $resolved === false ? $path : $resolved);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
