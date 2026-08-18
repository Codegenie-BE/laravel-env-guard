# Security Policy

## Supported versions

Security fixes are provided for the latest released major version of Laravel Env Guard.

## Secret handling

Laravel Env Guard is designed so environment **values are not part of its diagnostics**.

The package:

- reads environment files only to identify key names and `${KEY}` references;
- does not include environment values in findings, Artisan console output, exceptions, or log context;
- does not persist scan findings, fingerprints, or environment values in a result cache;
- never copies `.env` contents into GitHub, telemetry, or an external service;
- does not make network requests.

Console output, log records, and exceptions may contain environment **key names**, source paths, line numbers, finding codes, and sanitized messages. Every audit derives those diagnostics from the current project state and does not create `storage/framework/cache/laravel-env-guard.json` or another persistent findings file.

The Laravel optional-key policy also works from key presence only. It does not inspect, compare, persist, or report the supplied value when deciding whether an optional Laravel key has become active.

## Reporting a vulnerability

Please do not publish a security vulnerability in a public issue before it can be assessed. Contact Codegenie through the contact information on https://www.codegenie.be and include a minimal reproduction, affected versions, and expected impact.
