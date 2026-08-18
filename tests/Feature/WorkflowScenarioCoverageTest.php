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

    expect($workflow)
        ->not->toMatch('/(?:laravel\/framework|orchestra\/testbench|pestphp\/pest):\^/')
        ->not->toMatch('/(?:framework|testbench|pest):\s*[\'\"]\^/');

    assertWorkflowActionsArePinned($workflow);
});

it('tests the exact Composer distribution archive in a fresh Laravel application', function (): void {
    $workflow = (string) file_get_contents(__DIR__.'/../../.github/workflows/distribution.yml');

    foreach ([
        'composer archive --format=zip',
        'src/EnvGuard.php',
        'config/env-guard.php',
        'Development-only path leaked into release archive',
        'composer --working-dir="$package_root" validate --strict',
        'php tests/E2E/runner.php --laravel=13 --package-root="$PACKAGE_ROOT"',
    ] as $requirement) {
        expect($workflow)->toContain($requirement);
    }

    assertWorkflowActionsArePinned($workflow);
});

it('publishes immutable stable tags and verifies Packagist synchronization', function (): void {
    $workflow = (string) file_get_contents(__DIR__.'/../../.github/workflows/release.yml');

    foreach ([
        'contents: write',
        'fetch-depth: 0',
        'git tag -a "$TAG" "$GITHUB_SHA"',
        'git push origin "$TAG"',
        'gh release create "$TAG"',
        'https://repo.packagist.org/p2/${PACKAGE}.json',
        '.source.reference == $sha',
    ] as $requirement) {
        expect($workflow)->toContain($requirement);
    }

    expect($workflow)
        ->toContain('Existing tag %s points to %s instead of release commit %s.')
        ->toContain('Packagist did not expose %s for commit %s after repeated public metadata checks.');

    assertWorkflowActionsArePinned($workflow);
});

it('keeps temporary source-mutation workflows out of the maintained branch', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_file($root.'/.github/workflows/apply-cache-fingerprint-fix.yml'))->toBeFalse()
        ->and(is_file($root.'/.github/workflows/apply-ci-portability-fix.yml'))->toBeFalse();

    foreach (array_merge(
        glob($root.'/.github/workflows/*.yml') ?: [],
        glob($root.'/.github/workflows/*.yaml') ?: [],
    ) as $workflowPath) {
        $workflow = (string) file_get_contents($workflowPath);

        expect($workflow)
            ->not->toContain('Apply reviewed cache fingerprint fix')
            ->not->toContain('Apply CI portability fix');
    }
});

function assertWorkflowActionsArePinned(string $workflow): void
{
    preg_match_all('/^\s*uses:\s*([^\s#]+)/m', $workflow, $matches);

    expect($matches[1])->not->toBe([]);

    foreach ($matches[1] as $actionReference) {
        $separator = strrpos($actionReference, '@');
        $revision = $separator === false ? '' : substr($actionReference, $separator + 1);

        expect($revision)->toMatch('/^[a-f0-9]{40}$/i');
    }
}
