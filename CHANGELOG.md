# Changelog

All notable changes to Laravel Env Guard will be documented in this file.

## Unreleased

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
