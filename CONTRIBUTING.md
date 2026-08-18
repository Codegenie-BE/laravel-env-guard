# Contributing

Laravel Env Guard intentionally stays small and Laravel-native.

## Before opening a pull request

- Use [GitHub Discussions](https://github.com/Codegenie-BE/laravel-env-guard/discussions) for usage questions, configuration guidance and open-ended environment edge cases.
- Use the structured [issue forms](https://github.com/Codegenie-BE/laravel-env-guard/issues/new/choose) for reproducible bugs, false positives, false negatives and focused feature requests.
- Never include real `.env` values, tokens, credentials, private URLs, customer data or other secrets in issues, discussions, tests or pull requests.

Before proposing a code change:

1. Confirm the scenario is specific to Laravel environment correctness and not general-purpose static analysis.
2. Prefer PHP and Laravel built-ins over new dependencies.
3. Do not introduce handling that logs, stores, or prints environment values.
4. Add a focused Pest test for each new detection rule or false-positive fix.
5. Preserve Laravel 12 and Laravel 13 compatibility.

## Quality gates

Run the full quality gate before opening a pull request:

```bash
composer check
```

When detection behavior changes, also verify the coverage gate:

```bash
composer test:coverage
```

For focused work:

```bash
composer test
composer format:test
composer analyse
composer audit
```
