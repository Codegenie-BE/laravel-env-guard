# Laravel Env Guard scenario model

This document defines what Laravel Env Guard should and should not infer for Laravel 12 and 13.

## 1. Normal local development

Default behavior:

- package discovery loads the service provider automatically;
- the guard runs when `app()->environment()` is `local`;
- automatic full-project scans are console-first through `console_only`;
- no custom Artisan command is required;
- deterministic errors can fail fast;
- every audit reads current project files and current plaintext environment files;
- current findings are written to STDERR during guarded Artisan commands.

Production, staging, and testing are not scanned unless explicitly added to `env-guard.environments`. Normal HTTP request boots are skipped while `console_only` remains enabled.

## 2. Name-agnostic environment-file consistency

Laravel Env Guard does not require `.env.example` or any other canonical reference filename.

By default it uses Laravel's configured `environmentPath()` and discovers the plaintext environment files that actually exist there:

- `.env` when present;
- `.env.*` files such as `.env.testing`, `.env.production`, `.env.local`, `.env.template`, `.env.sample`, `.env.production.example`, and `.env.dist`;
- the active file reported by Laravel's `environmentFilePath()`, even when its name was customized through `loadEnvironmentFrom()`.

Common backup artifacts (`*.bak`, `*.backup`, `*.old`) and encrypted files (`*.encrypted`) are excluded from automatic plaintext inspection.

Default comparison semantics:

- every actively declared key found in one discovered environment file is expected in the other discovered environment files;
- a key missing from a peer produces `missing-from-environment-file`;
- if the missing peer is Laravel's active environment file, a real runtime environment variable can satisfy the key;
- values are never compared;
- a missing `.env.example` is not a finding because `.env.example` has no special status by default.

This makes renaming a template safe. For example, `.env.template` or `.env.production.example` participates automatically when it exists.

### Commented assignments

Non-active environment files can document optional keys with commented assignments:

```dotenv
# DB_HOST=127.0.0.1
# DB_PORT=3306
```

Those keys count as that file's documented key inventory. The behavior is based on file role, not on a `.env.example` filename.

Commented assignments in Laravel's active environment file do **not** count as active values. If another discovered file actively declares `SERVICE_TOKEN` while the active file contains only `# SERVICE_TOKEN=`, the active file receives a missing-key warning unless the runtime environment supplies `SERVICE_TOKEN`.

### Explicit reference mode

`reference_files` remains available for projects that intentionally want one or more documentation/reference files. It is empty by default.

Only an explicitly configured reference can produce `missing-reference-file`. Explicit reference mode preserves reference-vs-active checks, while `compare_files` can add specifically selected standalone comparison files.

This allows stricter project-specific contracts without making any one filename mandatory for every Laravel application.

## 3. Optional Laravel 12/13 framework keys

Laravel's stock config files contain many environment hooks for connections, drivers and services that a project may never activate.

Examples include `DB_URL`, Redis connection details, database cache/queue settings, SQS/DynamoDB/S3 settings, optional SMTP settings, Postmark/Resend/Slack credentials, session overrides and secondary logging channels.

The mere presence of such an `env()` call in stock Laravel config does not make the key a required project environment variable.

Default policy:

- a maintained exact-name catalog identifies Laravel 12/13 optional framework keys;
- if such a key is absent from all scanned environment files and the real runtime environment, `used-but-undeclared` noise is suppressed;
- once the key is actively declared or documented in a scanned environment file, or supplied by the runtime environment, the normal audit applies again;
- core selectors such as `DB_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `MAIL_MAILER`, `LOG_CHANNEL`, and `FILESYSTEM_DISK` are not part of this suppression;
- broad prefix ignores are not used.

Set `suppress_inactive_laravel_keys` to `false` for literal/strict behavior.

This policy suppresses only inactivity noise. It does not suppress unsafe `env()` usage outside config, duplicate keys, case mismatches, or environment-file drift for a key that the project actually supplies.

## 4. Artisan console diagnostics

When Env Guard is active and Laravel is running in console mode, current findings are written to STDERR by default.

The console report contains only:

- severity;
- finding code;
- environment key name when available;
- sanitized message;
- relative path and line number when available.

Every guarded Artisan boot performs a complete audit of the current relevant source and environment files. The same current findings are written to Laravel logging for that audit, and the console report is rendered without consulting persistent scan state.

Set `console_output` to `false` to disable the STDERR presentation layer without disabling the audit or Laravel logging.

## 5. Additional Laravel and Vite environment files

Laravel can load `.env.[APP_ENV]` when `APP_ENV` is provided externally or an Artisan command uses `--env`. Vite can also use `.env.local`, `.env.[mode]`, and related files.

Env Guard defaults to automatic `.env` / `.env.*` discovery and compares discovered plaintext files as peer key inventories. This default deliberately favors finding drift over assuming a specific filename is authoritative.

Some frontend projects intentionally use partial Vite layer files rather than complete environment contracts. Those projects can opt out explicitly:

```php
'discover_environment_files' => false,

'compare_files' => [
    '.env.testing',
    '.env.production',
],
```

With discovery disabled, the active Laravel environment file is still inspected. Explicit `compare_files` and `reference_files` remain available for the exact files the project wants to enforce.

## 6. Testing and `.env.testing`

Laravel uses `.env.testing` instead of `.env` for Pest/PHPUnit or Artisan with `--env=testing` when that file exists.

Because `.env.testing` matches `.env.*`, it participates in automatic discovery by default.

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

After Laravel configuration is cached, `.env` is not loaded for normal requests or commands. Application code should therefore consume `config()` values rather than call `env()` directly.

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

For keys that are declared in the project's scanned environment contract, these patterns are reported:

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

Duplicate active assignments in one environment file are blocking because the effective result may be ambiguous or order-dependent.

Commented assignments do not count as duplicate active assignments.

## 14. Case mismatches

`SERVICE_TOKEN` and `service_token` are treated as different names. If application usage or environment files differ only by case, the guard reports a blocking case-mismatch finding. This avoids development/production differences across operating systems and process environments.

## 15. Likely unused keys

A declared active key with no application-owned usage is reported as **possibly** unused, not proven unused.

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

Laravel recommends not running `config:cache` during local development. If configuration is already cached while Env Guard is active, the package reports a warning because the currently running application may not reflect environment edits.

Each audit reads Laravel's current configuration-cache state directly, so `config:cache` and `config:clear` transitions are observed without persistent Env Guard state.

## 18. Encrypted environment files

`*.encrypted` files are not auto-discovered. Encrypted values cannot be meaningfully audited without decryption, and Laravel Env Guard intentionally does not handle encryption keys.

Auditing an encrypted file's key set would require decryption, which is deliberately outside this package's responsibility. A project can keep a non-secret key contract in any plaintext `.env` / `.env.*` naming scheme; `.env.example` is one possible name, not a requirement.

## 19. Long-running workers and Octane

With `console_only` enabled by default, automatic audits run on guarded console boots, including Artisan-started long-running processes such as queue workers, Reverb, and Octane startup. Normal HTTP request boots do not trigger a full source scan. Restart or reload a long-running process after source or environment changes when you want its startup audit to run again.

## 20. Filesystem and performance constraints

Source analysis is limited to application-owned paths, configured project files and a configurable maximum file size. Explicit extensionless text files such as `Dockerfile` are supported, while binary files in configured project directories are skipped. Shell-style infrastructure references such as `${APP_NAME:-Laravel}` are recognized when the key belongs to the project.

Environment discovery is limited to Laravel's configured environment directory and simple `.env` / `.env.*` filename matching. Backup and encrypted artifacts are skipped.

Every audit reparses the current relevant files and does not persist findings or a source fingerprint between processes. `console_only` keeps this deterministic full scan off ordinary HTTP requests by default.

Symlinked source files are not followed by default, preventing accidental traversal outside the project tree.

## 21. Secrets

No finding should ever contain an environment value. Console output, exception messages, and logs contain only key names, finding codes, paths, line numbers and sanitized diagnostic text. Env Guard does not persist a findings cache.

This invariant is part of the package's security contract.

## 22. Fresh Laravel application contract

The package E2E suite installs the exact package into fresh Laravel 12 and Laravel 13 applications.

Those scenarios verify that:

- stock optional framework config does not produce `used-but-undeclared` noise;
- framework/tooling keys such as `BCRYPT_ROUNDS` and `BROADCAST_CONNECTION` are not reported as unused;
- an optional stock key becomes auditable as soon as the application supplies it;
- environment-file drift appears in Artisan output and Laravel logging;
- environment values never appear in either diagnostic channel;
- unsafe `env()` usage outside config still blocks Laravel boot;
- configuration-cache transitions are observed directly by subsequent audits without persistent scan state.

## 23. Non-goals

Laravel Env Guard is not intended to:

- infer whether credentials are semantically required by every possible third-party provider;
- guess arbitrary dynamic `env($key)` data flow;
- synchronize secret values between environments;
- copy environment files;
- enforce that values are identical across environments;
- replace a secrets manager;
- inspect production secrets remotely;
- modify environment files automatically;
- decrypt encrypted environment files;
- scan every dependency in `vendor/`;
- become a generic PHP static-analysis framework.
