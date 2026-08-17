# Contributing

Laravel Env Guard intentionally stays small and Laravel-native.

Before proposing a change:

1. Confirm the scenario is specific to Laravel environment correctness and not general-purpose static analysis.
2. Prefer PHP and Laravel built-ins over new dependencies.
3. Do not introduce handling that logs, stores, or prints environment values.
4. Add a focused Pest test for each new detection rule or false-positive fix.
5. Preserve Laravel 12 and Laravel 13 compatibility.

Run the quality gates before opening a pull request:

```bash
composer check
```

For focused work:

```bash
composer test
composer format:test
composer analyse
composer audit
```
