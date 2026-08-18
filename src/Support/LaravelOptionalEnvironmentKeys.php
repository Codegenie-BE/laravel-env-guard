<?php

namespace Codegenie\EnvGuard\Support;

final class LaravelOptionalEnvironmentKeys
{
    /**
     * Laravel 12/13 application config defines these keys as optional overrides,
     * connection details, driver-specific settings, or service credentials.
     *
     * Their mere presence in config/*.php does not make them a required project
     * environment contract. Once a project actually declares or externally
     * supplies one of these keys, Env Guard audits it normally.
     *
     * @var list<string>
     */
    private const KEYS = [
        'APP_MAINTENANCE_STORE',
        'APP_PREVIOUS_KEYS',
        'AUTH_GUARD',
        'AUTH_MODEL',
        'AUTH_PASSWORD_BROKER',
        'AUTH_PASSWORD_RESET_TOKEN_TABLE',
        'AUTH_PASSWORD_TIMEOUT',
        'AWS_ACCESS_KEY_ID',
        'AWS_BUCKET',
        'AWS_DEFAULT_REGION',
        'AWS_ENDPOINT',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_URL',
        'AWS_USE_PATH_STYLE_ENDPOINT',
        'BEANSTALKD_QUEUE',
        'BEANSTALKD_QUEUE_HOST',
        'BEANSTALKD_QUEUE_RETRY_AFTER',
        'CACHE_PREFIX',
        'CACHE_STORAGE_DISK',
        'CACHE_STORAGE_PATH',
        'DB_CACHE_CONNECTION',
        'DB_CACHE_LOCK_CONNECTION',
        'DB_CACHE_LOCK_TABLE',
        'DB_CACHE_TABLE',
        'DB_CHARSET',
        'DB_COLLATION',
        'DB_DATABASE',
        'DB_FOREIGN_KEYS',
        'DB_HOST',
        'DB_PASSWORD',
        'DB_PORT',
        'DB_QUEUE',
        'DB_QUEUE_CONNECTION',
        'DB_QUEUE_RETRY_AFTER',
        'DB_QUEUE_TABLE',
        'DB_SOCKET',
        'DB_SSLMODE',
        'DB_URL',
        'DB_USERNAME',
        'DYNAMODB_CACHE_TABLE',
        'DYNAMODB_ENDPOINT',
        'LOG_DAILY_DAYS',
        'LOG_DEPRECATIONS_CHANNEL',
        'LOG_DEPRECATIONS_TRACE',
        'LOG_LEVEL',
        'LOG_PAPERTRAIL_HANDLER',
        'LOG_SLACK_EMOJI',
        'LOG_SLACK_USERNAME',
        'LOG_SLACK_WEBHOOK_URL',
        'LOG_STACK',
        'LOG_STDERR_FORMATTER',
        'LOG_SYSLOG_FACILITY',
        'MAIL_EHLO_DOMAIN',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_HOST',
        'MAIL_LOG_CHANNEL',
        'MAIL_PASSWORD',
        'MAIL_PORT',
        'MAIL_SCHEME',
        'MAIL_SENDMAIL_PATH',
        'MAIL_URL',
        'MAIL_USERNAME',
        'MEMCACHED_HOST',
        'MEMCACHED_PASSWORD',
        'MEMCACHED_PERSISTENT_ID',
        'MEMCACHED_PORT',
        'MEMCACHED_USERNAME',
        'MYSQL_ATTR_SSL_CA',
        'PAPERTRAIL_PORT',
        'PAPERTRAIL_URL',
        'POSTMARK_API_KEY',
        'POSTMARK_TOKEN',
        'QUEUE_FAILED_DRIVER',
        'REDIS_BACKOFF_ALGORITHM',
        'REDIS_BACKOFF_BASE',
        'REDIS_BACKOFF_CAP',
        'REDIS_CACHE_CONNECTION',
        'REDIS_CACHE_DB',
        'REDIS_CACHE_LOCK_CONNECTION',
        'REDIS_CLIENT',
        'REDIS_CLUSTER',
        'REDIS_DB',
        'REDIS_HOST',
        'REDIS_MAX_RETRIES',
        'REDIS_PASSWORD',
        'REDIS_PERSISTENT',
        'REDIS_PORT',
        'REDIS_PREFIX',
        'REDIS_QUEUE',
        'REDIS_QUEUE_CONNECTION',
        'REDIS_QUEUE_RETRY_AFTER',
        'REDIS_URL',
        'REDIS_USERNAME',
        'RESEND_API_KEY',
        'RESEND_KEY',
        'SESSION_CONNECTION',
        'SESSION_COOKIE',
        'SESSION_DOMAIN',
        'SESSION_ENCRYPT',
        'SESSION_EXPIRE_ON_CLOSE',
        'SESSION_HTTP_ONLY',
        'SESSION_LIFETIME',
        'SESSION_PARTITIONED_COOKIE',
        'SESSION_PATH',
        'SESSION_SAME_SITE',
        'SESSION_SECURE_COOKIE',
        'SESSION_STORE',
        'SESSION_TABLE',
        'SLACK_BOT_USER_DEFAULT_CHANNEL',
        'SLACK_BOT_USER_OAUTH_TOKEN',
        'SQS_PREFIX',
        'SQS_QUEUE',
        'SQS_SUFFIX',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::KEYS;
    }

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
