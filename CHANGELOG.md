# Changelog

All notable changes to Laravel Env Guard will be documented in this file.

## Unreleased

## [1.2.2] - 2026-08-29

### Fixed

- PHP source scanning now follows aliased global helper imports such as `use function env as environment;`, so literal and dynamic environment access cannot bypass the existing `env()` diagnostics through a function alias.

## [1.2.1] - 2026-08-19

### Changed

- Environment-file discovery is now name-agnostic by default: every plaintext `.env` and `.env.*` file in Laravel's configured environment directory is scanned and compared by key inventory, so `.env.example` is no longer a required or implicit canonical reference file.
- Commented assignments in discovered non-active environment files count as documented key inventory, while commented assignments in Laravel's active environment file never satisfy active key presence.
- `reference_files` and `compare_files` remain available as explicit opt-in controls. A `missing-reference-file` warning is now emitted only for a reference file that the project explicitly configured.
- Renamed templates and `.env.dist` participate in automatic discovery; encrypted and common backup artifacts remain excluded from plaintext inspection.

## [1.2.0] - 2026-08-18

### Changed

- Automatic full-project audits are console-first by default through `console_only`, avoiding a complete static scan on every normal HTTP request.
- Every audit now reads and analyzes the current source/environment state directly; the persistent `laravel-env-guard.json` findings cache, metadata invalidation machinery, and `cache_path` configuration have been removed.
- `EnvGuard::inspect()` retains the legacy `fresh` and `fingerprint` result fields for 1.x compatibility; `fresh` is always `true` and the fingerprint is an in-memory signature of the current sanitized findings only.
- Upgrades from cache-based releases automatically remove the legacy default result cache and any previously configured custom `cache_path` on the next audit.

## [1.1.0] - 2026-08-18

### Added

- Fresh Laravel 12 and Laravel 13 end-to-end scenarios now verify package discovery, blocking `env()` misuse, secret-value non-disclosure, and configuration-cache transitions in real applications.
- Fresh-application E2E installs now use a deterministic copied Composer path repository instead of relying on VCS version inference or symlink behavior.
- CI now validates minimum dependency sets, Linux ARM64 portability, pull-request dependency changes, the full supported PHP/Laravel matrix, and an independent 80% coverage gate while skipping expensive jobs for documentation-only changes.
- CI portability now covers Windows-safe Composer version constraints and cross-platform environment-path assertions.
- Composer quality scripts now include strict manifest, security and optimized-autoload checks, with `check:all` providing the complete local quality plus Pest gate.
- Metadata cache invalidation now tracks Laravel configuration-cache state and cache path so `configuration-cached` diagnostics cannot become stale across `config:cache` and `config:clear` transitions.
- Filesystem regression coverage now verifies custom Laravel environment paths and filenames, configured maximum source size, and that symlinked source files are not followed.
- The exact Composer release archive is now validated for required runtime files, excluded development-only files, strict manifest validity, and installation into a fresh Laravel 13 application.
- Repository presentation now surfaces the existing Distribution workflow, a concise package tagline and the full scenario model near the top of the README.
- Guarded Artisan commands now render current warning/error findings to STDERR while retaining change-based Laravel logging and key-only secret-safe diagnostics.
- Laravel 12/13 optional framework keys now avoid `used-but-undeclared` noise while inactive and automatically re-enter normal auditing when declared or supplied at runtime.
- Fresh Laravel 12/13 E2E coverage now verifies optional-key suppression, activation, console visibility, Laravel logging, framework-owned keys, and value non-disclosure.

## [1.0.0] - 2026-08-18

### Added

- Automatic Laravel package discovery with development-only execution by default.
- Key-only parsing for `.env`, `.env.example`, `.env.testing`, and discovered `.env.*` files without persisting values.
- Duplicate key, case mismatch, missing key, likely unused key, and file-parity diagnostics.
- Static detection of `env()` and imported `Illuminate\Support\Env::get()` calls outside `config/`.
- Detection of dynamic `env()` calls that cannot be fully audited.
- Detection of direct `getenv()`, `$_ENV`, and `$_SERVER` access for keys declared by the project.
- Vite `import.meta.env.VITE_*`, Node `process.env`, and Vite `loadEnv()` usage detection.
- Dotenv `${KEY}` interpolation awareness.
- `.env.testing` awareness for environment values supplied through `phpunit.xml`.
- Custom Laravel environment path/file support.
- Metadata-only scan caching with no environment values or secrets persisted.
- Laravel 12 and Laravel 13 compatibility matrix for PHP 8.2 through PHP 8.5 where supported by Laravel.
- Token-based PHP environment access analysis that ignores comments and string literals, recognizes fully qualified `\env()` and aliased / fully qualified `Illuminate\Support\Env::get()`, and classifies concatenated keys as dynamic instead of literal.
- Precise Vite `loadEnv()` tracking that follows the assigned environment object instead of matching unrelated `VITE_*` object properties.
- Multiline dotenv parsing that ignores assignment-shaped content inside quoted values and still tracks valid `${KEY}` interpolation without retaining values.
- Additional project-file coverage for `public/index.php` and `phpunit.xml.dist`, plus shell-style infrastructure defaults such as `${APP_NAME:-Laravel}`.
- Dotenv key parsing aligned with current `vlucas/phpdotenv`, including quoted names, Unicode and numeric-leading names while rejecting invalid hyphenated names.
- Conservative standalone-env comparison semantics so auto-discovered Vite layer files do not produce missing-key completeness noise.
- Lowercase/custom Vite key detection, direct `loadEnv()` destructuring, and common `.mjs` / `.cjs` / `.mts` / `.cts` Vite config variants.
- Core Env Guard regression coverage for ignored keys, explicit comparisons, discovery behavior, caching, and secret-value non-persistence.
- Deduplicated source roots before recursive scanning so the default `config` directory is not traversed twice.
- Cache invalidation now includes behavior-affecting guard configuration and the presence of documented runtime environment keys without hashing or persisting secret values.
- Dotenv interpolation tracking now follows `phpdotenv` comment boundaries so `${KEY}` references inside inline comments are ignored while quoted content remains valid.
- The existing 80% test-coverage requirement is now enforced by the permanent GitHub Actions quality job.
- Recursive root scans now prune dependency, VCS, storage, and bootstrap cache trees before traversal.
- Text scanning now masks Blade, XML/HTML, JavaScript-style, and infrastructure comments while preserving diagnostic line offsets.
- PHP environment scanning now recognizes literal `key:` named arguments for `env()` and `Env::get()`.
- Reference completeness now catches keys used by the application but declared only outside configured reference files, without creating per-key cascades when reference checks are disabled or every reference file is missing.
- PHP scanning now handles reordered named arguments, grouped/comma imports, first-class callable syntax, named `getenv()` arguments, and raw-access case mismatches.
- Frontend scanning now distinguishes executable template expressions from comments, strings, regular-expression literals, and template text; it also covers direct environment destructuring.
- PHPUnit `<server>` variables, UTF-8 BOM environment files, explicit extensionless text project files, binary-file skipping, malformed ignore patterns, and cross-platform path comparison are now covered.

[1.2.2]: https://github.com/Codegenie-BE/laravel-env-guard/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/Codegenie-BE/laravel-env-guard/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/Codegenie-BE/laravel-env-guard/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Codegenie-BE/laravel-env-guard/compare/v1.0.0...v1.1.0
