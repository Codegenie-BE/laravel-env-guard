<?php

namespace Codegenie\EnvGuard\Scanners;

final class EnvironmentFileScanner
{
    /**
     * @return array{
     *     path:string,
     *     exists:bool,
     *     keys:array<string, array{line:int, commented:bool}>,
     *     duplicates:list<array{key:string, lines:list<int>>>,
     *     interpolations:list<array{key:string, line:int}>
     * }
     */
    public function scan(string $path, bool $includeCommented = false): array
    {
        $result = [
            'path' => $path,
            'exists' => is_file($path),
            'keys' => [],
            'duplicates' => [],
            'interpolations' => [],
        ];

        if (! $result['exists'] || ! is_readable($path)) {
            return $result;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return $result;
        }

        $lineNumber = 0;
        $seen = [];
        $duplicateLines = [];
        $multilineQuote = null;
        $name = '[\\p{L}_][\\p{L}\\p{N}_.-]*';

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $candidate = ltrim(rtrim($line, "\r\n"));

            if ($multilineQuote !== null) {
                if ($this->closesQuotedValue($candidate, $multilineQuote)) {
                    $multilineQuote = null;
                }

                continue;
            }

            $commented = false;

            if ($candidate === '') {
                continue;
            }

            if (str_starts_with($candidate, '#')) {
                if (! $includeCommented) {
                    continue;
                }

                $candidate = ltrim(substr($candidate, 1));
                $commented = true;
            }

            if (! preg_match('/^(?:export\\s+)?('.$name.')\\s*=(.*)$/u', $candidate, $matches)) {
                continue;
            }

            $key = $matches[1];
            $value = ltrim($matches[2]);

            if (! $commented) {
                if (isset($seen[$key])) {
                    $duplicateLines[$key] ??= [$seen[$key]];
                    $duplicateLines[$key][] = $lineNumber;
                } else {
                    $seen[$key] = $lineNumber;
                }
            }

            if (! isset($result['keys'][$key]) || ($result['keys'][$key]['commented'] && ! $commented)) {
                $result['keys'][$key] = [
                    'line' => $lineNumber,
                    'commented' => $commented,
                ];
            }

            if (! $commented && ! str_starts_with($value, "'") && preg_match_all('/(?<!\\\\)\\$\\{('.$name.')\\}/u', $value, $references)) {
                foreach ($references[1] as $reference) {
                    $result['interpolations'][] = [
                        'key' => $reference,
                        'line' => $lineNumber,
                    ];
                }
            }

            if (! $commented && ($value[0] ?? null) !== null && in_array($value[0], ["'", '"'], true)) {
                $quote = $value[0];

                if (! $this->closesQuotedValue(substr($value, 1), $quote)) {
                    $multilineQuote = $quote;
                }
            }

            unset($value);
        }

        fclose($handle);

        foreach ($duplicateLines as $key => $lines) {
            $result['duplicates'][] = [
                'key' => $key,
                'lines' => array_values(array_unique($lines)),
            ];
        }

        return $result;
    }

    private function closesQuotedValue(string $value, string $quote): bool
    {
        $escaped = false;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote === "'" && $character === "'") {
                return true;
            }

            if ($quote === '"') {
                if ($character === '"' && ! $escaped) {
                    return true;
                }

                if ($character === '\\') {
                    $escaped = ! $escaped;
                    continue;
                }

                $escaped = false;
            }
        }

        return false;
    }
}
