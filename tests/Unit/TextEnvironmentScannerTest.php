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

it('supports PHPUnit server variables and ignores XML CDATA examples', function () {
    $root = sys_get_temp_dir().'/env-guard-phpunit-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $path = $root.'/phpunit.xml';
    file_put_contents($path, <<<'XML'
<php>
    <!-- <env name="COMMENTED_ENV" value="one"/> -->
    <![CDATA[<server name="CDATA_SERVER" value="two"/>]]>
    <env name="REAL_ENV" value="three"/>
    <server name="REAL_SERVER" value="four"/>
</php>
XML);

    $result = (new TextEnvironmentScanner)->scan([$path], []);

    expect($result['phpunit_keys'])->toBe(['REAL_ENV', 'REAL_SERVER']);

    @unlink($path);
    @rmdir($root);
});

it('scans executable frontend expressions without treating strings or regular expressions as usage', function () {
    $temporary = tempnam(sys_get_temp_dir(), 'vite-');
    $path = $temporary.'.ts';
    rename($temporary, $path);
    file_put_contents($path, <<<'JS'
// import.meta.env.VITE_LINE_COMMENT
/* import.meta.env.VITE_BLOCK_COMMENT */
const quoted = "import.meta.env.VITE_STRING";
const bracketText = "import.meta.env['VITE_STRING_BRACKET']";
const regularExpression = /import\.meta\.env\.VITE_REGEX/;
const rawTemplate = `import.meta.env.VITE_TEMPLATE_TEXT`;
const actual = import.meta.env.VITE_REAL;
const bracket = import.meta.env['VITE_BRACKET'];
const ratio = total / import.meta.env.VITE_DIVISOR;
const template = `prefix ${import.meta.env.VITE_TEMPLATE_EXPRESSION}`;
const nested = `outer ${`inner ${import.meta.env.VITE_NESTED_EXPRESSION}`}`;
const { VITE_DESTRUCTURED: localName, VITE_DEFAULT = 'fallback' } = import.meta.env;
const { NODE_DESTRUCTURED } = process.env;
const env = loadEnv(mode, process.cwd(), '');
console.log(env.VITE_LOAD_ENV);
console.log(env['VITE_LOAD_BRACKET']);
const { VITE_LOAD_DESTRUCTURED } = env;
const { VITE_DIRECT_LOAD } = loadEnv(mode, process.cwd(), '');
JS);

    $result = (new TextEnvironmentScanner)->scan([$path], ['NODE_DESTRUCTURED']);
    $keys = array_column($result['usages'], 'key');

    expect($keys)->toContain(
        'VITE_REAL',
        'VITE_BRACKET',
        'VITE_DIVISOR',
        'VITE_TEMPLATE_EXPRESSION',
        'VITE_NESTED_EXPRESSION',
        'VITE_DESTRUCTURED',
        'VITE_DEFAULT',
        'NODE_DESTRUCTURED',
        'VITE_LOAD_ENV',
        'VITE_LOAD_BRACKET',
        'VITE_LOAD_DESTRUCTURED',
        'VITE_DIRECT_LOAD',
    )
        ->and($keys)->not->toContain(
            'VITE_LINE_COMMENT',
            'VITE_BLOCK_COMMENT',
            'VITE_STRING',
            'VITE_STRING_BRACKET',
            'VITE_REGEX',
            'VITE_TEMPLATE_TEXT',
        )
        ->and(array_count_values($keys)['VITE_REAL'] ?? 0)->toBe(1);

    @unlink($path);
});
