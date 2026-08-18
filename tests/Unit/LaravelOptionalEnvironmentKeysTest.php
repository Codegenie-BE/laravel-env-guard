<?php

use Codegenie\EnvGuard\Support\LaravelOptionalEnvironmentKeys;

it('covers Laravel 12 and 13 optional framework environment families without suppressing core selectors', function () {
    $keys = LaravelOptionalEnvironmentKeys::all();

    expect($keys)
        ->toContain(
            'APP_MAINTENANCE_STORE',
            'AUTH_GUARD',
            'DB_URL',
            'DB_CACHE_CONNECTION',
            'DB_QUEUE_CONNECTION',
            'REDIS_URL',
            'MAIL_URL',
            'POSTMARK_API_KEY',
            'RESEND_API_KEY',
            'AWS_ACCESS_KEY_ID',
            'SESSION_CONNECTION',
            'LOG_STDERR_FORMATTER',
            'SQS_QUEUE',
        )
        ->not->toContain(
            'APP_ENV',
            'APP_KEY',
            'DB_CONNECTION',
            'CACHE_STORE',
            'QUEUE_CONNECTION',
            'SESSION_DRIVER',
            'MAIL_MAILER',
            'LOG_CHANNEL',
            'FILESYSTEM_DISK',
        );

    $sorted = $keys;
    sort($sorted);

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and($keys)->toBe($sorted);
});
