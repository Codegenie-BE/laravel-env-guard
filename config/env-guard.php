<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automatic guard
    |--------------------------------------------------------------------------
    |
    | Laravel Env Guard is intentionally development-only by default. The
    | provider boots automatically through package discovery; no command is
    | required. Add "testing" or another environment only when you want it.
    |
    */
    'enabled' => true,

    'environments' => [
        'local',
    ],

    /* Throw only for findings that are deterministic code/configuration bugs. */
    'fail_on_error' => true,

    /*
    | Files used to document or compare environment keys. Relative paths are
    | resolved against Laravel's environment path, not necessarily base_path().
    */
    'reference_files' => [
        '.env.example',
    ],

    'compare_files' => [
        '.env.testing',
    ],

    'discover_environment_files' => true,

    /*
    | Application-owned source paths. Vendor and node_modules are deliberately
    | excluded: package internals should not force every optional vendor key
    | into the application's .env.example file.
    */
    'scan_paths' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'routes',
        'resources',
        'tests',
    ],

    'project_files' => [
        'composer.json',
        'package.json',
        'phpunit.xml',
        'phpunit.xml.dist',
        'public/index.php',
        'vite.config.js',
        'vite.config.ts',
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ],

    'project_directories' => [
        '.github/workflows',
        'scripts',
        'bin',
    ],

    'max_file_size' => 1_048_576,

    /* Keys consumed by Laravel itself rather than ordinary application code. */
    'known_external_keys' => [
        'LARAVEL_ENV_ENCRYPTION_KEY',
        'PHP_CLI_SERVER_WORKERS',
        'VITE_APP_NAME',
    ],

    /* Exact keys and regular expressions ignored by unused-key diagnostics. */
    'ignore_keys' => [],
    'ignore_patterns' => [],

    /*
    | Metadata-only result cache. Environment values are never written here.
    | Set to null to use storage/framework/cache/laravel-env-guard.json.
    */
    'cache_path' => null,
];
