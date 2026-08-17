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
        $multilineCommented = false;
        $name = '[\\p{L}_][\\p{L}\\p{N}_.-]*';

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $candidate = ltrim(rtrim($line, "\r\n"));

            if ($multilineQuote !== null) {
                $continuation = $candidate;

                if ($multilineCommented) {
                    if (! str_starts_with($continuation, '#')) {
                        $multilineQuote = null;
                        $multilineCommented = false;
                    } else {
                        $continuation = ltrim(substr($continuation, 1));
                    }
                }

                if ($multilineQuote !== null) {
                    if (! $multilineCommented && $multilineQuote === '"') {
                        $this->appendInterpolations($result['interpolations'], $continuation, $name, $lineNumber);
                    }

                    if ($this->closesQuotedValue($continuation, $multilineQuote)) {
                        $multilineQuote = null;
                        $multilineCommented = false;
                    }

                    continue;
                }
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

            if (! $commented && ! str_starts_with($value, "'")) {
                $this->appendInterpolations($result['interpolations'], $value, $name, $lineNumber);
            }

            if (($value[0] ?? null) !== null && in_array($value[0], ["'", '"'], true)) {
                $quote = $value[0];

                if (! $this->closesQuotedValue(substr($value, 1), $quote)) {
                    $multilineQuote = $quote;
                    $multilineCommented = $commented;
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

    /** @param list<array{key:string, line:int}> $target */
    private function appendInterpolations(array &$target, string $value, string $name, int $line): void
    {
        if (! preg_match_all('/\$\{('.$name.')\}/u', $value, $references, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($references[1] as $index => [$reference]) {
            $offset = $references[0][$index][1];
            $backslashes = 0;

            for ($position = $offset - 1; $position >= 0 && $value[$position] === '\\'; $position--) {
                $backslashes++;
            }

            if ($backslashes % 2 === 1) {
                continue;
            }

            $target[] = [
                'key' => $reference,
                'line' => $line,
            ];
        }
    }

    private function closesQuotedValue(string $value, string $quote): bool
    {
        $escaped = false;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($character === $quote && ! $escaped) {
                return true;
            }

            if ($character === '\\') {
                $escaped = ! $escaped;
                continue;
            }

            $escaped = false;
        }

        return false;
    }
}
