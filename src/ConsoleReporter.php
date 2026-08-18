<?php

namespace Codegenie\EnvGuard;

final class ConsoleReporter
{
    /**
     * @param  list<array<string, mixed>>  $findings
     */
    public function render(array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $warnings = count(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['severity'] ?? null) === 'warning',
        ));
        $errors = count(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['severity'] ?? null) === 'error',
        ));

        $lines = [
            sprintf(
                'Laravel Env Guard: %d warning(s), %d error(s)',
                $warnings,
                $errors,
            ),
        ];

        foreach ($findings as $finding) {
            $severity = strtoupper((string) ($finding['severity'] ?? 'warning'));
            $code = (string) ($finding['code'] ?? 'unknown');
            $key = isset($finding['key']) && is_string($finding['key'])
                ? ' '.$finding['key']
                : '';
            $message = (string) ($finding['message'] ?? 'Environment guard finding.');

            $lines[] = sprintf('%s [%s]%s: %s', $severity, $code, $key, $message);

            if (isset($finding['path']) && is_string($finding['path'])) {
                $location = $finding['path'];

                if (isset($finding['line']) && is_int($finding['line'])) {
                    $location .= ':'.$finding['line'];
                }

                $lines[] = '  '.$location;
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  resource|null  $stream
     */
    public function report(array $findings, $stream = null): void
    {
        $output = $this->render($findings);

        if ($output === '') {
            return;
        }

        $ownsStream = false;

        if (! is_resource($stream)) {
            $stream = @fopen('php://stderr', 'wb');
            $ownsStream = true;
        }

        if (! is_resource($stream)) {
            return;
        }

        @fwrite($stream, $output);

        if ($ownsStream) {
            @fclose($stream);
        }
    }
}
