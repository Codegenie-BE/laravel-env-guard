<?php

namespace Codegenie\EnvGuard\Scanners;

final class PhpEnvironmentScanner
{
    /**
     * @param list<string> $files
     * @return array{
     *     usages:list<array{key:string, path:string, line:int, in_config:bool, source:string}>,
     *     dynamic:list<array{path:string, line:int, in_config:bool}>,
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
            $count = count($tokens);

            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];

                if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
                    continue;
                }

                $previous = $this->previousSignificant($tokens, $index);

                if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    continue;
                }

                $openIndex = $this->nextSignificantIndex($tokens, $index);

                if ($openIndex === null || $tokens[$openIndex] !== '(') {
                    continue;
                }

                $argumentIndex = $this->nextSignificantIndex($tokens, $openIndex);
                $line = $token[2];

                if ($argumentIndex === null || ! is_array($tokens[$argumentIndex]) || $tokens[$argumentIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $result['dynamic'][] = [
                        'path' => $file,
                        'line' => $line,
                        'in_config' => $inConfig,
                    ];

                    continue;
                }

                $key = $this->decodeStringLiteral($tokens[$argumentIndex][1]);

                if ($key === null || $key === '') {
                    $result['dynamic'][] = [
                        'path' => $file,
                        'line' => $line,
                        'in_config' => $inConfig,
                    ];

                    continue;
                }

                $result['usages'][] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => $line,
                    'in_config' => $inConfig,
                    'source' => 'env',
                ];
            }

            $result['usages'] = array_merge($result['usages'], $this->scanEnvFacade($contents, $file, $inConfig));
            $result['raw'] = array_merge($result['raw'], $this->scanRawAccess($contents, $file));
        }

        return $result;
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

    /** @param array<int, array<int, mixed>|string> $tokens */
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

    private function decodeStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }

        $quote = $literal[0];
        $value = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }

        if ($quote === '"') {
            return stripcslashes($value);
        }

        return null;
    }

    /** @return list<array{key:string, path:string, line:int, in_config:bool, source:string}> */
    private function scanEnvFacade(string $contents, string $file, bool $inConfig): array
    {
        $aliases = [];

        if (preg_match_all('/use\\s+Illuminate\\\\Support\\\\Env(?:\\s+as\\s+([A-Za-z_][A-Za-z0-9_]*))?\\s*;/i', $contents, $imports, PREG_SET_ORDER)) {
            foreach ($imports as $import) {
                $aliases[] = ($import[1] ?? '') !== '' ? $import[1] : 'Env';
            }
        }

        $patterns = ['/\\\\Illuminate\\\\Support\\\\Env::get\\(\\s*([\'\"])([^\'\"]+)\\1/'];

        foreach (array_unique($aliases) as $alias) {
            $patterns[] = '/\\b'.preg_quote($alias, '/').'::get\\(\\s*([\'\"])([^\'\"]+)\\1/';
        }

        $usages = [];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[2] as [$key, $offset]) {
                $usages[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                    'in_config' => $inConfig,
                    'source' => 'Env::get',
                ];
            }
        }

        return $usages;
    }

    /** @return list<array{key:string, path:string, line:int, source:string}> */
    private function scanRawAccess(string $contents, string $file): array
    {
        $patterns = [
            'getenv' => '/\\bgetenv\\(\\s*([\'\"])([^\'\"]+)\\1\\s*\\)/',
            '$_ENV' => '/\\$_ENV\\s*\\[\\s*([\'\"])([^\'\"]+)\\1\\s*\\]/',
            '$_SERVER' => '/\\$_SERVER\\s*\\[\\s*([\'\"])([^\'\"]+)\\1\\s*\\]/',
        ];

        $usages = [];

        foreach ($patterns as $source => $pattern) {
            if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[2] as [$key, $offset]) {
                $usages[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                    'source' => $source,
                ];
            }
        }

        return $usages;
    }

    private function isWithin(string $file, string $directory): bool
    {
        $file = str_replace('\\', '/', $file);
        $directory = rtrim(str_replace('\\', '/', $directory), '/').'/';

        return str_starts_with($file, $directory);
    }
}
