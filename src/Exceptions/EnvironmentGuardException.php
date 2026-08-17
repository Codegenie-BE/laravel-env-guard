<?php

namespace Codegenie\EnvGuard\Exceptions;

use RuntimeException;

final class EnvironmentGuardException extends RuntimeException
{
    /** @param list<array<string, mixed>> $findings */
    public static function fromFindings(array $findings): self
    {
        $errors = array_values(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['severity'] ?? null) === 'error',
        ));

        $lines = ['Laravel Env Guard found '.count($errors).' blocking issue(s):'];

        foreach ($errors as $finding) {
            $location = isset($finding['path'])
                ? ' at '.$finding['path'].(isset($finding['line']) ? ':'.$finding['line'] : '')
                : '';

            $lines[] = sprintf(
                '- [%s] %s%s',
                $finding['code'] ?? 'error',
                $finding['message'] ?? 'Environment configuration error.',
                $location,
            );
        }

        return new self(implode(PHP_EOL, $lines));
    }
}
