# Security Policy

## Supported versions

Security fixes are provided for the latest released major version of Laravel Env Guard.

## Secret handling

Laravel Env Guard is designed so environment **values are not part of its diagnostics**.

The package:

- reads environment files only to identify key names and `${KEY}` references;
- does not include environment values in findings, exceptions, or log context;
- does not persist environment values in its metadata cache;
- never copies `.env` contents into GitHub, telemetry, or an external service;
- does not make network requests.

The metadata cache may contain environment **key names**, source paths, line numbers, finding codes, and messages. It is stored below Laravel's `storage` directory by default and written with restrictive permissions where the platform permits it.

## Reporting a vulnerability

Please do not publish a security vulnerability in a public issue before it can be assessed. Contact Codegenie through the contact information on https://www.codegenie.be and include a minimal reproduction, affected versions, and expected impact.
