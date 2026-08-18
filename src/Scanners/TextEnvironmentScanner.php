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

            $basename = basename($file);

            if ($basename === 'phpunit.xml' || $basename === 'phpunit.xml.dist') {
                if (preg_match_all('/<env\s+[^>]*name\s*=\s*([\'\"])([^\'\"]+)\1/i', $contents, $matches)) {
                    $result['phpunit_keys'] = array_values(array_unique(array_merge($result['phpunit_keys'], $matches[2])));
                }
            }

            $viteFilter = static fn (string $key): bool => str_starts_with($key, 'VITE_') || isset($declaredLookup[$key]);
            $declaredFilter = static fn (string $key): bool => isset($declaredLookup[$key]);

            $this->collectPattern($result['usages'], $contents, $file, '/\bimport\.meta\.env\.([A-Za-z_$][A-Za-z0-9_$]*)/', 'vite', $viteFilter);
            $this->collectPattern($result['usages'], $contents, $file, '/\bimport\.meta\.env\s*\[\s*[\'\"]([A-Za-z0-9_.]+)[\'\"]\s*\]/', 'vite', $viteFilter);
            $this->collectPattern($result['usages'], $contents, $file, '/\bprocess\.env\.([A-Za-z_$][A-Za-z0-9_$]*)/', 'node', $declaredFilter);
            $this->collectPattern($result['usages'], $contents, $file, '/\bprocess\.env\s*\[\s*[\'\"]([A-Za-z0-9_.]+)[\'\"]\s*\]/', 'node', $declaredFilter);

            if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*loadEnv\s*\(/', $contents, $loadEnvVariables)) {
                foreach (array_unique($loadEnvVariables[1]) as $variable) {
                    $quotedVariable = preg_quote($variable, '/');

                    $this->collectPattern(
                        $result['usages'],
                        $contents,
                        $file,
                        '/\b'.$quotedVariable.'\.([A-Za-z_$][A-Za-z0-9_$]*)/',
                        'vite-load-env',
                        $viteFilter,
                    );
                    $this->collectPattern(
                        $result['usages'],
                        $contents,
                        $file,
                        '/\b'.$quotedVariable.'\s*\[\s*[\'\"]([A-Za-z0-9_.]+)[\'\"]\s*\]/',
                        'vite-load-env',
                        $viteFilter,
                    );
                    $this->collectDestructuredLoadEnv(
                        $result['usages'],
                        $contents,
                        $file,
                        $variable,
                        $viteFilter,
                    );
                }
            }

            $this->collectDirectDestructuredLoadEnv(
                $result['usages'],
                $contents,
                $file,
                $viteFilter,
            );

            if (str_ends_with($file, '.blade.php') && preg_match_all('/(?<![A-Za-z0-9_:>])(?:\\\\)?env\(\s*([\'\"])([^\'\"]+)\1/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[2] as [$key, $offset]) {
                    $result['blade_env'][] = [
                        'key' => $key,
                        'path' => $file,
                        'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                    ];
                }
            }

            if ($declaredLookup !== [] && $this->isInfrastructureFile($file)) {
                $this->collectInfrastructureUsages($result['usages'], $contents, $file, $declaredLookup);
            }
        }

        return $result;
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
    private function collectDestructuredLoadEnv(
        array &$target,
        string $contents,
        string $file,
        string $variable,
        callable $filter,
    ): void {
        $pattern = '/\b(?:const|let|var)\s*\{([^}]*)\}\s*=\s*'.preg_quote($variable, '/').'\b/';

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

                $absoluteOffset = $membersOffset + $offset;
                $target[] = [
                    'key' => $key,
                    'path' => $file,
                    'line' => substr_count(substr($contents, 0, $absoluteOffset), "\n") + 1,
                    'source' => 'vite-load-env',
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

    private function isInfrastructureFile(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        $basename = basename($normalized);

        return str_contains($normalized, '/.github/workflows/')
            || str_contains($normalized, '/scripts/')
            || str_contains($normalized, '/bin/')
            || in_array($basename, [
                'compose.yaml',
                'compose.yml',
                'docker-compose.yaml',
                'docker-compose.yml',
                'Dockerfile',
            ], true);
    }
}
