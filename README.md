# Laravel Env Guard

[![Tests](https://github.com/Codegenie-BE/laravel-env-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-env-guard/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/codegenie-be/laravel-env-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-env-guard)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/releases)

**by [Codegenie](https://www.codegenie.be)**

Laravel Env Guard automatically audits environment-variable usage while a Laravel application boots in development. It catches environment drift before it becomes a deployment problem, without requiring a command and without storing secret values.

```bash
composer require --dev codegenie-be/laravel-env-guard
```

That is enough. Laravel package discovery registers the guard automatically.

By default it runs only when the application environment is `local`.

- [Packagist](https://packagist.org/packages/codegenie-be/laravel-env-guard)
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
| project uses a key not declared in any scanned env file | Warning |
| active `.env` key is missing from the configured reference files | Warning |
| used key exists only outside the configured reference files | Warning |
| active reference key is missing from the active env file | Warning |
| used key is missing from `.env.testing` or another env file | Warning |
| declared key appears unused | Warning |
| dynamic `env()` inside `config/` | Warning |
| config is cached while the guard is active | Warning |
| reference or active env file is missing | Warning |

Errors fail fast in guarded development environments by default. Warnings are logged when the scan result changes.

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

## Environment-file consistency

Given:

```dotenv
# .env
APP_NAME=Codegenie
ACME_TOKEN=local-secret
OLD_API_KEY=old-value
```

```dotenv
# .env.example
APP_NAME=Laravel
ACME_TOKEN=
```

and:

```php
// config/services.php
return [
    'acme' => ['token' => env('ACME_TOKEN')],
];
```

Laravel Env Guard can identify `OLD_API_KEY` as possibly unused without ever persisting `old-value` or `local-secret`.

It intentionally compares **keys**, not secret values. Different environments are expected to use different credentials, URLs, database names, application keys, and debug settings.

## Laravel 12 and 13 scenarios

The package understands the environment mechanisms used by current Laravel applications:

- the active file reported by Laravel's `environmentFilePath()`;
- custom environment directories through `environmentPath()`;
- `.env.example` documentation, including commented optional assignments;
- `.env.testing`;
- explicitly configured standalone environment files such as `.env.testing`;
- optional discovery of additional `.env.*` files for diagnostics without assuming they are complete standalone environments;
- environment variables supplied by `phpunit.xml` through `<env>` or `<server>`;
- external/server variables that already satisfy a key;
- Dotenv `${KEY}` interpolation;
- Vite `import.meta.env.VITE_*`;
- Vite `loadEnv()` access;
- declared Node `process.env.KEY` usage;
- Blade `env()` usage;
- configuration caching;
- long-running Laravel processes such as Octane and workers.

See [the full scenario model](docs/scenarios.md) for edge cases and intentional limitations.

## Automatic development-only execution

The default configuration is deliberately conservative:

```php
'enabled' => true,

'environments' => [
    'local',
],

'fail_on_error' => true,
```

Production and staging therefore do no source scan by default.

Testing is also excluded by default because Laravel boots an application repeatedly during a test suite. If your project wants automatic guard execution during tests:

```php
// config/env-guard.php
'environments' => [
    'local',
    'testing',
],
```

You can publish the configuration when customization is needed:

```bash
php artisan vendor:publish --tag=env-guard-config
```

Publishing is optional; the guard itself never requires an Artisan command.

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

Built-in Vite values such as `MODE`, `DEV`, `PROD`, `SSR`, and `BASE_URL` are not treated as missing Laravel project keys. References inside comments, ordinary strings, regular-expression literals, or the raw text of a template literal are ignored; executable `${...}` template expressions are scanned.

## `.env.testing` and `phpunit.xml`

Laravel can use `.env.testing` instead of `.env` during Pest/PHPUnit runs. A testing value may also be provided through `phpunit.xml`:

```xml
<php>
    <env name="CACHE_STORE" value="array"/>
</php>
```

When `CACHE_STORE` is absent from `.env.testing` but present as either an `<env>` or `<server>` entry in `phpunit.xml`, the guard treats it as supplied for the testing environment.

Completeness checks apply only to files listed in `compare_files`. Automatic `.env.*` discovery is disabled by default because Vite files such as `.env.local` and `.env.production` may layer on top of `.env` instead of replacing it. Enable discovery when you want diagnostics for additional files, or list a known standalone Laravel environment file explicitly in `compare_files`.

## Commented example keys

Current Laravel skeletons document optional variables by commenting out assignments:

```dotenv
# DB_HOST=127.0.0.1
# DB_PORT=3306
```

Laravel Env Guard counts those keys as documented but does not require them in `.env`.

## Dynamic keys

Static analysis cannot reliably resolve this:

```php
env('SERVICE_'.$driver);
```

or:

```php
env($key);
```

The guard reports the location instead of guessing. A dynamic lookup outside `config/` remains a blocking Laravel config-cache compatibility issue.

## Likely unused keys

Unused detection is intentionally phrased as **possibly unused**. A key can be consumed outside application-owned Laravel source, for example by:

- a package in `vendor/`;
- Docker or Sail;
- a deployment script;
- a hosting platform;
- a process manager;
- code that dynamically constructs the key.

Suppress intentional cases explicitly:

```php
'ignore_keys' => [
    'EXTERNAL_PLATFORM_TOKEN',
],

'ignore_patterns' => [
    '/^SAIL_/',
    '/^FORWARD_/',
],
```

The package deliberately prunes `vendor/`, `node_modules/`, `.git/`, `storage/`, and `bootstrap/cache/` even when a configured scan path points at the project root. Scanning dependencies or generated state would create false environment requirements and unnecessary boot-time work. Explicit extensionless project files such as `Dockerfile` are supported, while binary files in configured project directories are skipped.

## Performance

The guard scans application-owned files only and ignores symlink targets. Files above the configured size limit are skipped.

A fingerprint is built from scanned-file metadata, behavior-affecting guard configuration, and the presence (never the values) of documented runtime environment keys. Sanitized findings are cached at:

```text
storage/framework/cache/laravel-env-guard.json
```

When that fingerprint is unchanged, source files are not reparsed. This also prevents stale cache reuse when an externally supplied environment key appears or disappears between application boots. The cache contains key names, finding metadata, paths, and line numbers only. It never contains environment values.

Change the maximum scanned file size if necessary:

```php
'max_file_size' => 1_048_576,
```

## Custom Laravel environment paths

The active env file is not hard-coded to `base_path('.env')`. Laravel Env Guard asks the running Laravel application for its environment path and active environment file, so applications using `useEnvironmentPath()` or `loadEnvironmentFrom()` remain supported.

## Long-running processes

Laravel Env Guard runs when Laravel boots. Under Octane, a queue worker, Reverb, or another long-running process, that means the audit runs when that process starts. Reload/restart the process after changing source or environment files, just as you would for other boot-time configuration changes.

## Encrypted environment files

Laravel supports `.env.encrypted` files. Laravel Env Guard does not auto-discover encrypted files and does not handle Laravel's encryption key.

This is intentional: an encrypted environment file cannot be audited key-by-key without decrypting its contents, and secret decryption is outside this package's responsibility. Compare the plaintext `.env.example` or another non-secret reference file instead.

## Configuration

Default configuration:

```php
return [
    'enabled' => true,
    'environments' => ['local'],
    'fail_on_error' => true,

    'reference_files' => ['.env.example'],
    'compare_files' => ['.env.testing'],
    'discover_environment_files' => false,

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
        'LARAVEL_ENV_ENCRYPTION_KEY',
        'PHP_CLI_SERVER_WORKERS',
        'VITE_APP_NAME',
    ],

    'ignore_keys' => [],
    'ignore_patterns' => [],
    'cache_path' => null,
];
```

## Compatibility

| Laravel | Supported PHP versions |
| --- | --- |
| 12.x | 8.2 - 8.5 |
| 13.x | 8.3 - 8.5 |

CI tests every valid Laravel/PHP combination in that matrix and runs an additional portability check on Windows and macOS.

## Security model

Laravel Env Guard follows several hard rules:

- no telemetry;
- no network requests;
- no automatic `.env` modifications;
- no secret synchronization;
- no environment values in logs;
- no environment values in exceptions;
- no environment values in the metadata cache;
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
