# Laravel Env Guard scenario model

This document defines what Laravel Env Guard should and should not infer for Laravel 12 and 13.

## 1. Normal local development

Default behavior:

- package discovery loads the service provider automatically;
- the guard runs when `app()->environment()` is `local`;
- no custom Artisan command is required;
- deterministic errors can fail fast;
- warnings are logged when the scan result changes.

Production, staging, and testing are not scanned unless explicitly added to `env-guard.environments`.

## 2. `.env` and `.env.example`

The guard treats the active Laravel environment file and `.env.example` differently:

- active assignments in `.env` represent locally provided keys;
- active and commented assignments in `.env.example` count as documentation;
- a key present in `.env` but not documented in `.env.example` is reported;
- an active key in `.env.example` that is absent from `.env` is reported unless Laravel can already resolve it from an external environment variable;
- commented example keys are optional documentation and do not have to exist in `.env`.

Values are deliberately not compared. Different environments are expected to have different values, especially for credentials, URLs, databases, debug flags, and application keys.

## 3. Additional Laravel environment files

Laravel can load `.env.[APP_ENV]` when `APP_ENV` is provided externally or an Artisan command uses `--env`.

The guard uses Laravel's own `environmentFilePath()` and `environmentPath()` so custom environment paths and active files are respected. Automatic `.env.*` discovery is disabled by default, because Laravel standalone environment files and Vite's layered `.env.local` / `.env.[mode]` files have different completeness semantics. The active Laravel file is always inspected, while known standalone files such as `.env.testing` are listed explicitly in `compare_files`.

When discovery is enabled, additional files are still inspected for key-level diagnostics such as duplicates and casing, but missing-key completeness warnings are emitted only for files explicitly listed in `compare_files`.

## 4. Testing and `.env.testing`

Laravel uses `.env.testing` instead of `.env` for Pest/PHPUnit or Artisan with `--env=testing` when that file exists.

`phpunit.xml` can also define environment values. When a project key is absent from `.env.testing` but is supplied through `<env name="...">`, the guard does not report it as missing from the testing environment file.

The package itself defaults to `local` only so it does not add a complete project scan to every test application bootstrap. Teams that want automatic test-environment guarding may add `testing` explicitly.

## 5. Valid `env()` usage

Laravel documents `env()` as a configuration-file concern. Literal calls in `config/*.php` are accepted:

```php
return [
    'token' => env('SERVICE_TOKEN'),
];
```

The scanner also recognizes an imported `Illuminate\Support\Env::get('KEY')` call as environment usage.

## 6. `env()` outside `config/`

Calls in controllers, models, services, routes, providers, bootstrap files, database code, tests, or Blade templates are blocking findings by default.

After Laravel configuration is cached, `.env` is not loaded for normal requests/commands. Application code should therefore consume `config()` values rather than call `env()` directly.

## 7. Dynamic environment keys

Examples:

```php
env($key);
env('SERVICE_'.$driver);
```

A static audit cannot reliably enumerate the resulting keys. The package reports the dynamic call. Dynamic calls outside `config/` are blocking; dynamic calls inside `config/` are warnings.

## 8. Raw PHP environment access

For keys that are declared in the project's environment files, these patterns are reported:

```php
getenv('SERVICE_TOKEN');
$_ENV['SERVICE_TOKEN'];
$_SERVER['SERVICE_TOKEN'];
```

The scanner intentionally ignores raw access to names that are not declared by the project so ordinary operating-system variables do not create noise.

## 9. Vite and frontend variables

Laravel projects commonly expose `VITE_*` variables to frontend assets. The guard recognizes:

```js
import.meta.env.VITE_APP_NAME
import.meta.env['VITE_API_URL']
```

It also recognizes `VITE_*` values accessed after Vite's `loadEnv()` helper and declared `process.env.KEY` references. Built-in Vite values such as `MODE`, `DEV`, `PROD`, `SSR`, and `BASE_URL` are not treated as missing project keys.

## 10. Dotenv interpolation

A declaration such as:

```dotenv
MAIL_FROM_NAME="${APP_NAME}"
```

counts as usage of `APP_NAME`. The value itself is not stored.

## 11. Duplicate keys

Duplicate active assignments in one environment file are blocking because the effective result may be ambiguous or order-dependent. Commented `.env.example` documentation does not count as a duplicate active assignment.

## 12. Case mismatches

`SERVICE_TOKEN` and `service_token` are treated as different names. If application usage differs only by case from a declared key, the guard reports a blocking case-mismatch finding. This avoids development/production differences across operating systems and process environments.

## 13. Likely unused keys

A declared key with no application-owned usage is reported as **possibly** unused, not proven unused.

Reasons a warning may be a legitimate false positive include:

- a third-party package consumes the key inside `vendor/`;
- Docker, Sail, a process manager, or hosting platform consumes it;
- deployment automation consumes it;
- code constructs the key dynamically;
- a runtime outside Laravel consumes it.

`ignore_keys` and `ignore_patterns` are provided for these intentional cases. Laravel-owned `PHP_CLI_SERVER_WORKERS` and `LARAVEL_ENV_ENCRYPTION_KEY`, plus the default skeleton `VITE_APP_NAME`, are excluded from unused diagnostics by default.

## 14. Composer packages and `vendor/`

`vendor/` is intentionally not scanned. Scanning all third-party source would make every optional package variable look like an application requirement and would slow local boot substantially.

Application-owned published package configuration under `config/` is scanned normally.

## 15. Configuration cached during development

Laravel recommends not running `config:cache` during local development. If configuration is already cached while Env Guard is active, the package reports a warning because the currently running application may not reflect `.env` edits.

## 16. Encrypted environment files

`.env.encrypted` files are not auto-discovered. Encrypted values cannot be meaningfully audited without decryption, and Laravel Env Guard intentionally does not handle encryption keys.

Auditing an encrypted file's key set would require decryption, which is deliberately outside this package's responsibility. Use a plaintext non-secret reference such as `.env.example` for key documentation instead.

## 17. Long-running workers and Octane

The guard runs when the Laravel application boots. With Octane, queue workers, Reverb, or another long-running process, that means the scan runs when the worker/application starts rather than on every handled request. Restart/reload the worker after environment or source changes, as required for other boot-time configuration changes.

## 18. Filesystem and performance constraints

Source analysis is limited to application-owned paths, configured project files and a configurable maximum file size. Shell-style infrastructure references such as `${APP_NAME:-Laravel}` are recognized when the key belongs to the project. The result cache stores a metadata fingerprint plus sanitized findings. On the next Laravel bootstrap, unchanged metadata reuses the prior result instead of reparsing source files.

Symlinked files are not followed by default, preventing accidental traversal outside the project tree.

## 19. Secrets

No finding should ever contain an environment value. Exception messages and logs contain only key names, finding codes, paths, and line numbers. This invariant is part of the package's security contract.

## 20. Non-goals

Laravel Env Guard is not intended to:

- synchronize secret values between environments;
- copy `.env` files;
- enforce that values are identical across environments;
- replace a secrets manager;
- inspect production secrets remotely;
- modify environment files automatically;
- scan every dependency in `vendor/`;
- become a generic PHP static-analysis framework.
