# Laravel Env Guard scenario model

This document defines what Laravel Env Guard should and should not infer for Laravel 12 and 13.

## 1. Normal local development

Default behavior:

- package discovery loads the service provider automatically;
- the guard runs when `app()->environment()` is `local`;
- no custom Artisan command is required;
- deterministic errors can fail fast;
- findings are logged when the scan result changes;
- current findings are written to STDERR during guarded Artisan commands.

Production, staging, and testing are not scanned unless explicitly added to `env-guard.environments`.

## 2. `.env` and `.env.example`

The guard treats the active Laravel environment file and `.env.example` differently:

- active assignments in `.env` represent locally provided keys;
- active and commented assignments in `.env.example` count as documentation;
- a key present in `.env` but not documented in the configured reference files is reported;
- a key used by the application but declared only in a comparison or discovered env file is still reported as missing from the configured reference files;
- an active key in `.env.example` that is absent from `.env` is reported unless Laravel can already resolve it from an external environment variable;
- commented example keys are optional documentation and do not have to exist in `.env`;
- commented assignments in the active `.env` do not count as active values.

Values are deliberately not compared. Different environments are expected to have different values, especially for credentials, URLs, databases, debug flags, and application keys.

## 3. Optional Laravel 12/13 framework keys

Laravel's stock config files contain many environment hooks for connections, drivers and services that a project may never activate.

Examples include `DB_URL`, Redis connection details, database cache/queue settings, SQS/DynamoDB/S3 settings, optional SMTP settings, Postmark/Resend/Slack credentials, session overrides and secondary logging channels.

The mere presence of such an `env()` call in stock Laravel config does not make the key a required project environment variable.

Default policy:

- a maintained exact-name catalog identifies Laravel 12/13 optional framework keys;
- if such a key is absent from all scanned environment files and the real runtime environment, `used-but-undeclared`-style noise is suppressed;
- once the key is actively declared in `.env`, a configured reference/comparison/discovered file, or supplied by the runtime environment, the normal audit applies again;
- core selectors such as `DB_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `MAIL_MAILER`, `LOG_CHANNEL`, and `FILESYSTEM_DISK` are not part of this suppression;
- broad prefix ignores are not used.

Set `suppress_inactive_laravel_keys` to `false` for literal/strict behavior.

This policy suppresses only inactivity noise. It does not suppress unsafe `env()` usage outside config, duplicate keys, case mismatches, or documentation drift for a key that the project actually supplies.

## 4. Artisan console diagnostics

When Env Guard is active and Laravel is running in console mode, current findings are written to STDERR by default.

The console report contains only:

- severity;
- finding code;
- environment key name when available;
- sanitized message;
- relative path and line number when available.

Console reporting is intentionally independent from the scan cache. Cached findings therefore remain visible on later Artisan commands.

Laravel log output remains change-based: findings are logged only when the scan result is fresh. This keeps the log useful without repeating the same warning on every command.

Set `console_output` to `false` to disable this presentation layer without disabling the audit.

## 5. Additional Laravel environment files

Laravel can load `.env.[APP_ENV]` when `APP_ENV` is provided externally or an Artisan command uses `--env`.

The guard uses Laravel's own `environmentFilePath()` and `environmentPath()` so custom environment paths and active files are respected. Automatic `.env.*` discovery is disabled by default, because Laravel standalone environment files and Vite's layered `.env.local` / `.env.[mode]` files have different completeness semantics. The active Laravel file is always inspected, while known standalone files such as `.env.testing` are listed explicitly in `compare_files`.

When discovery is enabled, additional files are still inspected for key-level diagnostics such as duplicates and casing, but missing-key completeness warnings are emitted only for files explicitly listed in `compare_files`.

## 6. Testing and `.env.testing`

Laravel uses `.env.testing` instead of `.env` for Pest/PHPUnit or Artisan with `--env=testing` when that file exists.

`phpunit.xml` can also define environment values. When a project key is absent from `.env.testing` but is supplied through `<env name="...">` or `<server name="...">`, the guard does not report it as missing from the testing environment file.

The package itself defaults to `local` only so it does not add a complete project scan to every test application bootstrap. Teams that want automatic test-environment guarding may add `testing` explicitly.

## 7. Valid `env()` usage

Laravel documents `env()` as a configuration-file concern. Literal calls in `config/*.php` are accepted:

```php
return [
    'token' => env('SERVICE_TOKEN'),
];
```

The scanner also recognizes an imported `Illuminate\Support\Env::get('KEY')` call as environment usage.

## 8. `env()` outside `config/`

Calls in controllers, models, services, routes, providers, bootstrap files, database code, tests, or Blade templates are blocking findings by default.

After Laravel configuration is cached, `.env` is not loaded for normal requests/commands. Application code should therefore consume `config()` values rather than call `env()` directly.

The optional Laravel-key policy never suppresses this finding, even when the key name is a stock framework optional key.

## 9. Dynamic environment keys

Examples:

```php
env($key);
env('SERVICE_'.$driver);
```

A static audit cannot reliably enumerate the resulting keys. The package reports the dynamic call. Dynamic calls outside `config/` are blocking; dynamic calls inside `config/` are warnings.

A wrapper that forwards a variable into `env($key)` remains dynamic unless the key can be resolved statically. The guard does not guess through arbitrary data flow.

## 10. Raw PHP environment access

For keys that are declared in the project's environment files, these patterns are reported:

```php
getenv('SERVICE_TOKEN');
$_ENV['SERVICE_TOKEN'];
$_SERVER['SERVICE_TOKEN'];
```

The scanner intentionally ignores raw access to names that are not declared by the project so ordinary operating-system variables do not create noise.

## 11. Vite and frontend variables

Laravel projects commonly expose `VITE_*` variables to frontend assets. The guard recognizes:

```js
import.meta.env.VITE_APP_NAME
import.meta.env['VITE_API_URL']
```

It also recognizes destructuring from `import.meta.env`, `VITE_*` values accessed after Vite's `loadEnv()` helper, and declared `process.env.KEY` references. Executable expressions inside JavaScript template literals are scanned, while comments, ordinary strings, regular-expression literals, and template-literal text are ignored. Built-in Vite values such as `MODE`, `DEV`, `PROD`, `SSR`, and `BASE_URL` are not treated as missing project keys.

## 12. Dotenv interpolation

A declaration such as:

```dotenv
MAIL_FROM_NAME="${APP_NAME}"
```

counts as usage of `APP_NAME`. The value itself is not stored.

## 13. Duplicate keys

Duplicate active assignments in one environment file are blocking because the effective result may be ambiguous or order-dependent. Commented `.env.example` documentation does not count as a duplicate active assignment.

## 14. Case mismatches

`SERVICE_TOKEN` and `service_token` are treated as different names. If application usage differs only by case from a declared key, the guard reports a blocking case-mismatch finding. This avoids development/production differences across operating systems and process environments.

## 15. Likely unused keys

A declared key with no application-owned usage is reported as **possibly** unused, not proven unused.

Reasons a warning may be a legitimate false positive include:

- a third-party package consumes the key inside `vendor/`;
- Laravel/framework tooling consumes it;
- Docker, Sail, a process manager, or hosting platform consumes it;
- deployment automation consumes it;
- code constructs the key dynamically;
- a runtime outside Laravel consumes it.

`BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`, `PHP_CLI_SERVER_WORKERS`, `LARAVEL_ENV_ENCRYPTION_KEY`, and the default skeleton `VITE_APP_NAME` are known framework/tooling keys and excluded from unused diagnostics by default.

`ignore_keys` and `ignore_patterns` are provided for additional intentional cases.

## 16. Composer packages and `vendor/`

`vendor/` is intentionally not scanned. Scanning all third-party source would make every optional package variable look like an application requirement and would slow local boot substantially.

Application-owned published package configuration under `config/` is scanned normally.

## 17. Configuration cached during development

Laravel recommends not running `config:cache` during local development. If configuration is already cached while Env Guard is active, the package reports a warning because the currently running application may not reflect `.env` edits.

The metadata fingerprint includes the Laravel configuration-cache state and the active cache path.

## 18. Encrypted environment files

`.env.encrypted` files are not auto-discovered. Encrypted values cannot be meaningfully audited without decryption, and Laravel Env Guard intentionally does not handle encryption keys.

Auditing an encrypted file's key set would require decryption, which is deliberately outside this package's responsibility. Use a plaintext non-secret reference such as `.env.example` for key documentation instead.

## 19. Long-running workers and Octane

The guard runs when the Laravel application boots. With Octane, queue workers, Reverb, or another long-running process, that means the scan runs when the worker/application starts rather than on every handled request. Restart/reload the worker after environment or source changes, as required for other boot-time configuration changes.

## 20. Filesystem and performance constraints

Source analysis is limited to application-owned paths, configured project files and a configurable maximum file size. Explicit extensionless text files such as `Dockerfile` are supported, while binary files in configured project directories are skipped. Shell-style infrastructure references such as `${APP_NAME:-Laravel}` are recognized when the key belongs to the project.

The result cache stores a metadata fingerprint plus sanitized findings. On the next Laravel bootstrap, unchanged metadata reuses the prior result instead of reparsing source files.

The optional Laravel-key policy feeds its inactive exact-key set into the normal ignore-key configuration before inspection. Because ignore-key configuration participates in the fingerprint, a key becoming active or inactive invalidates an incompatible cached result.

Symlinked files are not followed by default, preventing accidental traversal outside the project tree.

## 21. Secrets

No finding should ever contain an environment value. Console output, exception messages, logs and the metadata cache contain only key names, finding codes, paths, line numbers and sanitized diagnostic text.

This invariant is part of the package's security contract.

## 22. Fresh Laravel application contract

The package E2E suite installs the exact package into fresh Laravel 12 and Laravel 13 applications.

Those scenarios verify that:

- stock optional framework config does not produce `used-but-undeclared` noise;
- framework/tooling keys such as `BCRYPT_ROUNDS` and `BROADCAST_CONNECTION` are not reported as unused;
- an optional stock key becomes auditable as soon as the application supplies it;
- that warning appears both in Artisan output and Laravel logging;
- environment values never appear in either diagnostic channel;
- unsafe `env()` usage outside config still blocks Laravel boot;
- configuration-cache transitions invalidate the metadata cache correctly.

## 23. Non-goals

Laravel Env Guard is not intended to:

- infer whether credentials are semantically required by every possible third-party provider;
- guess arbitrary dynamic `env($key)` data flow;
- synchronize secret values between environments;
- copy `.env` files;
- enforce that values are identical across environments;
- replace a secrets manager;
- inspect production secrets remotely;
- modify environment files automatically;
- scan every dependency in `vendor/`;
- become a generic PHP static-analysis framework.
