<?php

declare(strict_types=1);

it('keeps the complete risk-based CI scenario model enforced', function (): void {
    $workflow = (string) file_get_contents(__DIR__.'/../../.github/workflows/tests.yml');

    foreach ([
        'name: CI gate',
        'minimum-dependencies:',
        'end-to-end:',
        'portability:',
        'dependency-review:',
        'coverage:',
        "php: '8.2'\n            laravel: '12'",
        "php: '8.3'\n            laravel: '13'",
        "php: '8.5'\n            laravel: '12'",
        "php: '8.5'\n            laravel: '13'",
        'ubuntu-24.04-arm',
        'composer update --prefer-lowest --prefer-stable',
        'composer test:e2e -- --laravel=${{ matrix.runtime.laravel }}',
        'composer test:coverage',
        'actions/dependency-review-action@',
    ] as $requirement) {
        expect($workflow)->toContain($requirement);
    }

    preg_match_all('/^\s*uses:\s*([^\s#]+)/m', $workflow, $matches);

    expect($matches[1])->not->toBe([]);

    foreach ($matches[1] as $actionReference) {
        $separator = strrpos($actionReference, '@');
        $revision = $separator === false ? '' : substr($actionReference, $separator + 1);

        expect($revision)->toMatch('/^[a-f0-9]{40}$/i');
    }
});

it('keeps temporary source-mutation workflows out of the maintained branch', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_file($root.'/.github/workflows/apply-cache-fingerprint-fix.yml'))->toBeFalse();

    foreach (array_merge(
        glob($root.'/.github/workflows/*.yml') ?: [],
        glob($root.'/.github/workflows/*.yaml') ?: [],
    ) as $workflowPath) {
        $workflow = (string) file_get_contents($workflowPath);

        expect($workflow)->not->toContain('Apply reviewed cache fingerprint fix');
    }
});
