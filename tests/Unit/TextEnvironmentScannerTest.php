<?php

use Codegenie\EnvGuard\Scanners\TextEnvironmentScanner;

it('detects Vite, Blade, phpunit and infrastructure usage', function () {
    $root = sys_get_temp_dir().'/env-guard-text-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);

    $vite = $root.'/app.ts';
    $blade = $root.'/view.blade.php';
    $phpunit = $root.'/phpunit.xml';
    $compose = $root.'/compose.yaml';

    file_put_contents($vite, "const name = import.meta.env.VITE_APP_NAME;\nconst mode = import.meta.env.MODE;\n");
    file_put_contents($blade, "{{ env('BLADE_KEY') }}\n");
    file_put_contents($phpunit, '<php><env name="TEST_KEY" value="1"/></php>');
    file_put_contents($compose, "services:\n  app:\n    environment:\n      APP_NAME: \${APP_NAME:-Laravel}\n");

    $result = (new TextEnvironmentScanner)->scan([$vite, $blade, $phpunit, $compose], [
        'VITE_APP_NAME',
        'BLADE_KEY',
        'TEST_KEY',
        'APP_NAME',
    ]);

    expect(array_column($result['usages'], 'key'))->toContain('VITE_APP_NAME', 'APP_NAME')
        ->and(array_column($result['usages'], 'key'))->not->toContain('MODE')
        ->and($result['blade_env'][0]['key'])->toBe('BLADE_KEY')
        ->and($result['phpunit_keys'])->toBe(['TEST_KEY']);

    foreach ([$vite, $blade, $phpunit, $compose] as $file) {
        @unlink($file);
    }

    @rmdir($root);
});

it('ignores environment references inside text comments', function () {
    $root = sys_get_temp_dir().'/env-guard-comments-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);

    $vite = $root.'/app.ts';
    $blade = $root.'/view.blade.php';
    $phpunit = $root.'/phpunit.xml';
    $compose = $root.'/compose.yaml';

    file_put_contents($vite, <<<'JS'
// import.meta.env.VITE_LINE_COMMENT
/* import.meta.env.VITE_BLOCK_COMMENT */
const actual = import.meta.env.VITE_REAL;
JS);
    file_put_contents($blade, "{{-- {{ env('BLADE_COMMENT') }} --}}\n{{ env('BLADE_REAL') }}\n");
    file_put_contents($phpunit, '<php><!-- <env name="COMMENTED_TEST" value="1"/> --><env name="REAL_TEST" value="1"/></php>');
    file_put_contents($compose, <<<'YAML'
# COMMENTED_INFRA: ${COMMENTED_INFRA}
services:
  app:
    environment:
      REAL_INFRA: ${REAL_INFRA}
      LABEL: "#${QUOTED_INFRA}"
YAML);

    $result = (new TextEnvironmentScanner)->scan([$vite, $blade, $phpunit, $compose], [
        'COMMENTED_INFRA',
        'REAL_INFRA',
        'QUOTED_INFRA',
    ]);
    $usageKeys = array_column($result['usages'], 'key');

    expect($usageKeys)->toContain('VITE_REAL', 'REAL_INFRA', 'QUOTED_INFRA')
        ->and($usageKeys)->not->toContain('VITE_LINE_COMMENT', 'VITE_BLOCK_COMMENT', 'COMMENTED_INFRA')
        ->and(array_column($result['blade_env'], 'key'))->toBe(['BLADE_REAL'])
        ->and($result['phpunit_keys'])->toBe(['REAL_TEST']);

    foreach ([$vite, $blade, $phpunit, $compose] as $file) {
        @unlink($file);
    }

    @rmdir($root);
});

it('detects Vite loadEnv access', function () {
    $path = tempnam(sys_get_temp_dir(), 'vite-');
    file_put_contents($path, <<<'JS'
import { loadEnv } from 'vite';
const env = loadEnv(mode, process.cwd(), '');
console.log(env.VITE_HMR_HOST);
console.log(env['DECLARED_BACKEND_KEY']);
const { VITE_OTHER_KEY, VITE_ALIASED: localName, backend: VITE_FALSE_ALIAS } = env;
const unrelated = object.VITE_FALSE_POSITIVE;
JS);

    $result = (new TextEnvironmentScanner)->scan([$path], ['DECLARED_BACKEND_KEY']);
    $keys = array_column($result['usages'], 'key');

    expect($keys)->toContain('VITE_HMR_HOST', 'VITE_OTHER_KEY', 'VITE_ALIASED', 'DECLARED_BACKEND_KEY')
        ->and($keys)->not->toContain('VITE_FALSE_POSITIVE', 'VITE_FALSE_ALIAS');

    @unlink($path);
});

it('detects lowercase Vite keys and direct loadEnv destructuring', function () {
    $path = tempnam(sys_get_temp_dir(), 'vite-');
    file_put_contents($path, <<<'JS'
import { loadEnv } from 'vite';
console.log(import.meta.env.VITE_lowercase);
console.log(import.meta.env['VITE_name.with.dot']);
const { VITE_direct, APP_port: port } = loadEnv(mode, process.cwd(), '');
JS);

    $result = (new TextEnvironmentScanner)->scan([$path], ['APP_port']);
    $keys = array_column($result['usages'], 'key');

    expect($keys)->toContain('VITE_lowercase', 'VITE_name.with.dot', 'VITE_direct', 'APP_port');

    @unlink($path);
});
