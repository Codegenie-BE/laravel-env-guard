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
