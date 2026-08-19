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

    /*
    | A full static project audit is useful for Artisan and development tooling,
    | but not on every normal HTTP request. Disable this only when intentional.
    */
    'console_only' => true,

    /* Throw only for findings that are deterministic code/configuration bugs. */
    'fail_on_error' => true,

    /*
    | Show current findings on STDERR whenever a guarded Artisan command boots.
    | Each audit reads the current project state directly from disk.
    */
    'console_output' => true,

    /*
    | Laravel's stock config files define many optional connection, driver and
    | service keys. Do not treat an absent stock optional key as a project
    | requirement until the project declares or externally supplies that key.
    */
    'suppress_inactive_laravel_keys' => true,

    /*
    | Environment files are discovered by filename pattern, not by one required
    | canonical name. By default every plaintext .env / .env.* file in Laravel's
    | configured environment directory is scanned and compared by key inventory.
    |
    | Set reference_files only when a project intentionally wants one or more
    | explicit documentation/reference files. A missing explicitly configured
    | reference is reported; no reference filename is required by default.
    |
    | compare_files can add explicit standalone files, including paths outside
    | automatic discovery. Relative paths resolve against environmentPath().
    */
    'reference_files' => [],

    'compare_files' => [],

    'discover_environment_files' => true,

    /*
    | Application-owned source paths. Vendor and node_modules are deliberately
    | excluded: package internals should not force every optional vendor key
    | into the application's environment files.
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
        'vite.config.mjs',
        'vite.config.cjs',
        'vite.config.ts',
        'vite.config.mts',
        'vite.config.cts',
        'Dockerfile',
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

    /* Keys consumed by Laravel/framework tooling rather than app-owned source. */
    'known_external_keys' => [
        'BCRYPT_ROUNDS',
        'BROADCAST_CONNECTION',
        'LARAVEL_ENV_ENCRYPTION_KEY',
        'PHP_CLI_SERVER_WORKERS',
        'VITE_APP_NAME',
    ],

    /* Exact keys and regular expressions ignored by unused-key diagnostics. */
    'ignore_keys' => [],
    'ignore_patterns' => [],
];
