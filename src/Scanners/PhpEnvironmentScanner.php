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

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            $isEnvHelper = ($token[0] === T_STRING && strtolower($token[1]) === 'env')
                || ($token[0] === T_NAME_FULLY_QUALIFIED && strtolower($token[1]) === '\\env');

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

            $nameIndex = $this->nextSignificantIndex($tokens, $index);

            if ($nameIndex === null || ! is_array($tokens[$nameIndex])) {
                continue;
            }

            $nameToken = $tokens[$nameIndex];

            if (! in_array($nameToken[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                || strtolower(ltrim($nameToken[1], '\\')) !== 'illuminate\\support\\env') {
                continue;
            }

            $alias = 'Env';
            $nextIndex = $this->nextSignificantIndex($tokens, $nameIndex);

            if ($nextIndex !== null && $this->tokenIs($tokens[$nextIndex], T_AS)) {
                $aliasIndex = $this->nextSignificantIndex($tokens, $nextIndex);

                if ($aliasIndex !== null && is_array($tokens[$aliasIndex]) && $tokens[$aliasIndex][0] === T_STRING) {
                    $alias = $tokens[$aliasIndex][1];
                }
            }

            $aliases[strtolower($alias)] = true;
        }

        return $aliases;
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
        $argumentIndex = $this->nextSignificantIndex($tokens, $openIndex);

        if ($argumentIndex === null || ! is_array($tokens[$argumentIndex]) || $tokens[$argumentIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            $result['dynamic'][] = [
                'path' => $file,
                'line' => $line,
                'in_config' => $inConfig,
                'source' => $source,
            ];

            return;
        }

        $afterArgument = $this->nextSignificantIndex($tokens, $argumentIndex);

        if ($afterArgument === null || ! in_array($tokens[$afterArgument], [')', ','], true)) {
            $result['dynamic'][] = [
                'path' => $file,
                'line' => $line,
                'in_config' => $inConfig,
                'source' => $source,
            ];

            return;
        }

        $key = $this->decodeStringLiteral($tokens[$argumentIndex][1]);

        if ($key === null || $key === '') {
            $result['dynamic'][] = [
                'path' => $file,
                'line' => $line,
                'in_config' => $inConfig,
                'source' => $source,
            ];

            return;
        }

        $result['usages'][] = [
            'key' => $key,
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
                $argumentIndex = $openIndex === null ? null : $this->nextSignificantIndex($tokens, $openIndex);

                if ($openIndex === null || $tokens[$openIndex] !== '(' || $argumentIndex === null) {
                    continue;
                }

                $key = $this->literalKeyAt($tokens, $argumentIndex);
                $afterArgument = $this->nextSignificantIndex($tokens, $argumentIndex);

                if ($key !== null && $afterArgument !== null && in_array($tokens[$afterArgument], [')', ','], true)) {
                    $usages[] = [
                        'key' => $key,
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
        $file = str_replace('\\', '/', $file);
        $directory = rtrim(str_replace('\\', '/', $directory), '/').'/';

        return str_starts_with($file, $directory);
    }
}
