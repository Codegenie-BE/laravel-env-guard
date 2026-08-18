# Laravel Env Guard

[![Tests](https://github.com/Codegenie-BE/laravel-env-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-env-guard/actions/workflows/tests.yml)
[![Distribution](https://github.com/Codegenie-BE/laravel-env-guard/actions/workflows/distribution.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-env-guard/actions/workflows/distribution.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/codegenie-be/laravel-env-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-env-guard)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/releases)

**by [Codegenie](https://www.codegenie.be)**

**Catch Laravel environment drift and unsafe `env()` usage before configuration caching turns it into a deployment bug.**

Laravel Env Guard automatically audits environment-variable usage during guarded Artisan boots in development. Every audit reads the current project files directly, compares the environment files that actually exist, and does not require a separate command, a persistent findings cache, or a specifically named `.env.example` file.

```bash
composer require --dev codegenie-be/laravel-env-guard
```

Laravel package discovery registers the guard automatically. By default it runs only when the application environment is `local`.

- [Packagist](https://packagist.org/packages/codegenie-be/laravel-env-guard)
- [Read the full scenario model](docs/scenarios.md)
- [Ask a question](https://github.com/Codegenie-BE/laravel-env-guard/discussions)
- [Report a bug or detection issue](https://github.com/Codegenie-BE/laravel-env-guard/issues/new/choose)

## What it checks

Laravel Env Guard combines key-only environment-file inspection with lightweight static analysis of application-owned source files.

| Finding | Default severity |
| --- | --- |
| `env()` outside `config/` | Error |
| imported `Illuminate\Support\Env::get()` outside `config/` | Error |
| dynamic `env()` outside `config/` | Error |
| raw `getenv()` / `$_ENV` / `$_SERVER` access for a project key | Error |
| duplicate active key in an environment file | Error |
| key differs only by case | Error |
| project uses a non-optional key not declared in any scanned env file | Warning |
| an actively declared key is missing from another discovered `.env` / `.env.*` file | Warning |
| explicitly configured reference or comparison files drift from the project | Warning |
| declared key appears unused | Warning |
| dynamic `env()` inside `config/` | Warning |
| config is cached while the guard is active | Warning |
| Laravel's active environment file is missing | Warning |
| an explicitly configured reference file is missing | Warning |

Errors fail fast in guarded development environments by default. During Artisan commands, current warnings and errors are written to STDERR as a key-only report.

## Environment-file discovery and consistency

The default behavior is intentionally **name-agnostic**.

Env Guard asks Laravel for its configured `environmentPath()` and active environment filename, then automatically discovers:

- `.env`, when present;
- every plaintext `.env.*` file in the same environment directory;
- renamed templates such as `.env.template`, `.env.sample`, `.env.production.example`, or another `.env.*` name;
- `.env.dist`.

It does **not** require `.env.example`. If a project renames or removes `.env.example`, that is not a warning by itself.

Encrypted files and common backup artifacts are skipped automatically because they are not normal plaintext environment sources:

- `*.encrypted`
- `*.bak`
- `*.backup`
- `*.old`

Given:

```dotenv
# .env
APP_NAME=Codegenie
ACME_TOKEN=local-secret
```

and:

```dotenv
# .env.production
APP_NAME=Codegenie
```

Env Guard reports that `ACME_TOKEN` is missing from `.env.production`.

The inverse is also checked. If `.env.production` contains an actively declared key that `.env` does not contain, the active `.env` receives a `missing-from-environment-file` warning unless the key is already supplied by the runtime environment.

Env Guard intentionally compares **keys**, not values. Different environments are expected to use different credentials, URLs, database names, application keys, and debug settings. Environment values are never included in findings.

### Commented template keys

A renamed template may document optional keys with comments:

```dotenv
APP_NAME=Laravel
# DB_HOST=127.0.0.1
# DB_PORT=3306
```

Commented assignments in a **non-active** discovered environment file count as that file's documented key inventory. They therefore do not need a special `.env.example` filename.

A commented assignment in Laravel's **active** environment file does not count as an active value. For example, if `.env.local` is the active Laravel file and contains only `# SERVICE_TOKEN=`, while another environment file actively declares `SERVICE_TOKEN`, Env Guard still reports that `SERVICE_TOKEN` is missing from the active environment.

## Explicit reference and comparison mode

Most projects do not need to configure environment filenames manually.

`reference_files` remains available when a project intentionally wants documentation/reference semantics instead of the default peer comparison. Only files explicitly placed in `reference_files` can produce `missing-reference-file`.

For example:

```php
'reference_files' => [
    '.env.contract',
],
```

If `.env.contract` does not exist, Env Guard reports it because the project explicitly asked for that file.

`compare_files` can add explicit standalone environment files, including files outside automatic discovery when needed.

For projects that deliberately use partial Vite-style layer files and do not want every `.env.*` file treated as a complete peer, disable automatic discovery and list only the intended standalone files:

```php
'discover_environment_files' => false,

'compare_files' => [
    '.env.testing',
    '.env.production',
],
```

This opt-out is explicit; automatic discovery and peer comparison are the default.

## Why `env()` outside `config/` matters

Laravel's configuration cache changes how environment values are available. After configuration is cached, Laravel does not load `.env` for normal requests or Artisan commands. Laravel therefore recommends calling `env()` only from configuration files and reading configuration elsewhere with `config()`.

Valid:

```php
// config/services.php
return [
    'acme' => [
        'token' => env('ACME_TOKEN'),
    ],
];
```

```php
// app/Services/AcmeClient.php
$token = config('services.acme.token');
```

Reported:

```php
// app/Services/AcmeClient.php
$token = env('ACME_TOKEN');
```

## Laravel 12 and 13 scenarios

The package understands the environment mechanisms used by current Laravel applications:

- the active file reported by Laravel's `environmentFilePath()`;
- custom environment directories through `environmentPath()`;
- automatic plaintext `.env` / `.env.*` discovery;
- renamed template/reference filenames without a hard-coded `.env.example` dependency;
- commented optional assignments in non-active environment templates;
- `.env.testing`;
- explicitly configured reference and standalone comparison files;
- environment variables supplied by `phpunit.xml` through `<env>` or `<server>`;
- external/server variables that already satisfy a key;
- Laravel 12/13 stock optional connection, driver, service, logging, session and authentication keys;
- Dotenv `${KEY}` interpolation;
- Vite `import.meta.env.VITE_*`;
- Vite `loadEnv()` access;
- declared Node `process.env.KEY` usage;
- Blade `env()` usage;
- configuration caching;
- long-running Laravel processes such as Octane and workers.

See [the full scenario model](docs/scenarios.md) for edge cases and intentional limitations.

## Automatic development-only execution

The default configuration is deliberately conservative about **when** the audit runs:

```php
'enabled' => true,

'environments' => [
    'local',
],

'console_only' => true,

'fail_on_error' => true,
```

Production and staging therefore do no source scan by default. Normal HTTP requests are skipped by default because `console_only` is `true`.

Testing is also excluded by default because Laravel boots an application repeatedly during a test suite. If a project wants automatic guard execution during tests:

```php
// config/env-guard.php
'environments' => [
    'local',
    'testing',
],
```

Publish the configuration only when customization is needed:

```bash
php artisan vendor:publish --tag=env-guard-config
```

Publishing is optional; the guard itself never requires an Artisan command.

## Artisan console output

When a guarded application boots through Artisan and findings exist, Env Guard writes a compact key-only report to STDERR:

```text
Laravel Env Guard: 1 warning(s), 0 error(s)
WARNING [missing-from-environment-file] CUSTOM_KEY: Environment key CUSTOM_KEY is present in another scanned environment file but missing from .env.production.
  .env.production
```

A missing `.env.example` is not reported unless `.env.example` was explicitly configured in `reference_files`.

Each guarded Artisan boot performs a complete audit of the current relevant files. Findings are logged for that same audit; no persistent result cache is consulted.

Disable console reporting without disabling the guard:

```php
'console_output' => false,
```

No environment value is included in console output.

## Optional Laravel framework keys

Laravel 12 and 13 ship config definitions for many settings that a given application may never use. Examples include alternative database settings, Redis options, database cache/queue settings, SQS/DynamoDB/S3 settings, optional SMTP settings, Postmark/Resend/Slack credentials, session overrides and secondary logging channels.

The existence of `env('REDIS_URL')` or `env('MAIL_URL')` in stock Laravel config does not by itself mean every project must declare that key.

By default, Env Guard suppresses `used-but-undeclared` noise for a maintained exact list of Laravel 12/13 optional framework keys only while the key is genuinely inactive.

The key becomes fully auditable as soon as it is:

- actively declared in any scanned environment file;
- documented in a discovered non-active environment file;
- declared in an explicitly configured reference or comparison file;
- supplied through the real runtime environment.

For example, a project that never configures `LOG_DAILY_DAYS` receives no warning merely because Laravel's `config/logging.php` knows about it. If the project adds `LOG_DAILY_DAYS` to one environment file but not its other discovered peers, normal environment-file drift reporting applies.

Core project selectors such as `DB_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `MAIL_MAILER`, `LOG_CHANNEL`, and `FILESYSTEM_DISK` are deliberately not part of optional-key suppression.

Strict projects can restore literal behavior:

```php
'suppress_inactive_laravel_keys' => false,
```

The optional-key policy uses exact names rather than broad prefixes, so custom project keys are not silently hidden.

## `.env.testing` and `phpunit.xml`

Laravel can use `.env.testing` instead of `.env` during Pest/PHPUnit runs. Because `.env.testing` matches `.env.*`, it is discovered automatically by default when it exists.

A testing value may also be provided through `phpunit.xml`:

```xml
<php>
    <env name="CACHE_STORE" value="array"/>
</php>
```

When `CACHE_STORE` is absent from `.env.testing` but supplied through either an `<env>` or `<server>` entry in `phpunit.xml`, the guard treats it as supplied for the testing environment.

## Vite

These are recognized as project environment usage:

```js
const name = import.meta.env.VITE_APP_NAME;
const url = import.meta.env['VITE_API_URL'];
const message = `API: ${import.meta.env.VITE_API_URL}`;
const { VITE_APP_NAME: applicationName } = import.meta.env;
```

Vite configuration that loads environment values explicitly is also recognized:

```js
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        server: {
            host: env.VITE_HMR_HOST,
        },
    };
});
```

Built-in Vite values such as `MODE`, `DEV`, `PROD`, `SSR`, and `BASE_URL` are not treated as missing Laravel project keys. References inside comments, ordinary strings, regular-expression literals, or raw template-literal text are ignored; executable `${...}` template expressions are scanned.

If a project uses Vite `.env.local` / `.env.[mode]` files purely as partial layers rather than complete environment contracts, disable automatic discovery and explicitly list only the files whose key inventories should be compared.

## Dynamic keys

Static analysis cannot reliably resolve:

```php
env('SERVICE_'.$driver);
```

or:

```php
env($key);
```

The guard reports the location instead of guessing. A dynamic lookup outside `config/` remains a blocking Laravel config-cache compatibility issue.

## Raw PHP environment access

For project environment keys, these patterns are reported:

```php
getenv('SERVICE_TOKEN');
$_ENV['SERVICE_TOKEN'];
$_SERVER['SERVICE_TOKEN'];
```

The scanner intentionally ignores unrelated operating-system variables that are not part of the project's scanned environment contract.

## Likely unused keys

Unused detection is intentionally phrased as **possibly unused**. A key can be consumed outside application-owned Laravel source by a package, framework tooling, Docker, Sail, deployment automation, hosting infrastructure, a process manager, dynamic code, or another runtime.

Laravel-owned `BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`, `PHP_CLI_SERVER_WORKERS` and `LARAVEL_ENV_ENCRYPTION_KEY`, plus the default skeleton `VITE_APP_NAME`, are excluded from unused diagnostics by default.

Suppress additional intentional cases explicitly:

```php
'ignore_keys' => [
    'EXTERNAL_PLATFORM_TOKEN',
],

'ignore_patterns' => [
    '/^SAIL_/',
    '/^FORWARD_/',
],
```

## Filesystem and performance

Every guarded audit reparses the current application-owned source and current environment files. Laravel Env Guard does not persist findings or a source fingerprint between processes.

Automatic full-project scans are console-first by default, so ordinary HTTP requests do not repeatedly pay the static-analysis cost. Direct calls to `EnvGuard::inspect()` always inspect the current filesystem state.

The scanner deliberately prunes `vendor/`, `node_modules/`, `.git/`, `storage/`, and `bootstrap/cache/`, ignores symlink targets, skips binary configured project files, and enforces a configurable maximum source file size:

```php
'max_file_size' => 1_048_576,
```

There is no `laravel-env-guard.json` result cache to clear. When upgrading from a cache-based version, the next audit removes the legacy default cache file and any previously configured custom `cache_path`.

## Custom Laravel environment paths

The active env file is not hard-coded to `base_path('.env')`. Env Guard asks the running Laravel application for `environmentPath()` and `environmentFilePath()`. Automatic discovery happens inside that configured environment directory, so `useEnvironmentPath()` and `loadEnvironmentFrom()` remain supported.

## Long-running processes

With `console_only` enabled, automatic audits run when a guarded console process boots. That includes Artisan-started long-running processes such as queue workers, Reverb, and Octane startup, while normal HTTP request boots are skipped.

Restart or reload a long-running process after source or environment changes when you want its startup audit to run again.

## Encrypted environment files

Laravel supports encrypted environment files. Env Guard deliberately excludes `*.encrypted` from automatic discovery and does not handle decryption keys.

An encrypted environment file cannot be audited key-by-key without decrypting it, and secret decryption is outside this package's responsibility. Keep any non-secret environment contract in whichever plaintext `.env` / `.env.*` file naming scheme your project uses.

## Configuration

Default configuration:

```php
return [
    'enabled' => true,
    'environments' => ['local'],
    'console_only' => true,
    'fail_on_error' => true,
    'console_output' => true,
    'suppress_inactive_laravel_keys' => true,

    'reference_files' => [],
    'compare_files' => [],
    'discover_environment_files' => true,

    'scan_paths' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'routes',
        'resources',
        'tests',
    ],

    'project_files' => [
        'composer.json',
        'package.json',
        'phpunit.xml',
        'phpunit.xml.dist',
        'public/index.php',
        'vite.config.js',
        'vite.config.mjs',
        'vite.config.cjs',
        'vite.config.ts',
        'vite.config.mts',
        'vite.config.cts',
        'Dockerfile',
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ],

    'project_directories' => [
        '.github/workflows',
        'scripts',
        'bin',
    ],

    'max_file_size' => 1_048_576,

    'known_external_keys' => [
        'BCRYPT_ROUNDS',
        'BROADCAST_CONNECTION',
        'LARAVEL_ENV_ENCRYPTION_KEY',
        'PHP_CLI_SERVER_WORKERS',
        'VITE_APP_NAME',
    ],

    'ignore_keys' => [],
    'ignore_patterns' => [],
];
```

## Compatibility

| Laravel | Supported PHP versions |
| --- | --- |
| 12.x | 8.2 - 8.5 |
| 13.x | 8.3 - 8.5 |

CI tests every valid Laravel/PHP combination in that matrix and runs additional minimum-dependency, E2E and cross-platform portability checks.

## Security model

Laravel Env Guard follows hard rules:

- no telemetry;
- no network requests;
- no automatic `.env` modifications;
- no secret synchronization;
- no environment values in console output;
- no environment values in logs;
- no environment values in exceptions;
- no persistent findings or fingerprint cache;
- no production scanning by default.

See [SECURITY.md](SECURITY.md).

## Quality gates

```bash
composer check
```

Runs Composer validation and audit, Pint, PHPStan/Larastan, and Pest.

Individual checks:

```bash
composer test
composer format:test
composer analyse
composer audit
composer test:coverage
```

The E2E suite installs the exact package into fresh Laravel 12 and 13 applications and verifies package discovery, optional framework-key behavior, environment-file drift, console/log diagnostics, secret non-disclosure, blocking unsafe `env()` usage and configuration-cache transitions.

## Support and contributing

Use [GitHub Discussions](https://github.com/Codegenie-BE/laravel-env-guard/discussions) for usage questions and open-ended environment edge cases. Use the structured [issue forms](https://github.com/Codegenie-BE/laravel-env-guard/issues/new/choose) for reproducible bugs, false positives, false negatives and focused feature requests.

Never post real environment values or other secrets. See [SUPPORT.md](SUPPORT.md), [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## Design boundary

Laravel Env Guard is intentionally separate from [Laravel Config Cache Guard](https://github.com/Codegenie-BE/laravel-config-cache-guard).

- **Laravel Env Guard** checks whether application-owned environment definitions and usage are internally consistent during development.
- **Laravel Config Cache Guard** protects deployments from stale Laravel config and route cache.

They solve related but different lifecycle problems and do not depend on each other.

## License

MIT. See [LICENSE.md](LICENSE.md).
