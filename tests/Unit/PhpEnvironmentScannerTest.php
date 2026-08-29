<?php

use Codegenie\EnvGuard\Scanners\PhpEnvironmentScanner;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/env-guard-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/config', 0777, true);
    mkdir($this->root.'/app', 0777, true);
});

afterEach(function () {
    foreach (glob($this->root.'/*/*.php') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->root.'/config');
    @rmdir($this->root.'/app');
    @rmdir($this->root);
});

it('distinguishes valid config env calls from unsafe application calls', function () {
    file_put_contents($this->root.'/config/services.php', <<<'PHPFILE'
<?php
return ['token' => env('SERVICE_TOKEN')];
PHPFILE);
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
$value = env("OUTSIDE_TOKEN");
$dynamic = env($name);
$concatenated = env('PREFIX_'.$name);
$fullyQualified = \env('FULLY_QUALIFIED');
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/config/services.php',
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect($result['usages'])->toHaveCount(3)
        ->and($result['usages'][0]['in_config'])->toBeTrue()
        ->and($result['usages'][1]['in_config'])->toBeFalse()
        ->and(array_column($result['usages'], 'key'))->toContain('FULLY_QUALIFIED')
        ->and($result['dynamic'])->toHaveCount(2)
        ->and($result['dynamic'][0]['in_config'])->toBeFalse();
});

it('recognizes literal environment keys passed with named arguments', function () {
    file_put_contents($this->root.'/config/services.php', <<<'PHPFILE'
<?php
return ['token' => env(key: 'NAMED_CONFIG')];
PHPFILE);
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
use Illuminate\Support\Env as LaravelEnv;

$one = env(key: 'NAMED_OUTSIDE');
$two = LaravelEnv::get(key: 'NAMED_FACADE');
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/config/services.php',
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect(array_column($result['usages'], 'key'))->toBe(['NAMED_CONFIG', 'NAMED_OUTSIDE', 'NAMED_FACADE'])
        ->and($result['dynamic'])->toBe([]);
});

it('detects facade and raw environment access without scanning comments or strings', function () {
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
use Illuminate\Support\Env as LaravelEnv;

$one = LaravelEnv::get('ONE');
$two = \Illuminate\Support\Env::get('TWO');
$dynamic = LaravelEnv::get('PREFIX_'.$suffix);
$three = getenv('THREE', true);
$four = \getenv('FOUR');
$five = $_ENV['FIVE'];
$six = $_SERVER["SIX"];
// LaravelEnv::get('COMMENT_ONLY');
$text = "getenv('STRING_ONLY')";
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect(array_column($result['usages'], 'key'))->toBe(['ONE', 'TWO'])
        ->and($result['dynamic'])->toHaveCount(1)
        ->and($result['dynamic'][0]['source'])->toBe('Env::get')
        ->and(array_column($result['raw'], 'key'))->toBe(['THREE', 'FOUR', 'FIVE', 'SIX']);
});

it('supports reordered named arguments, import groups and first-class callables', function () {
    file_put_contents($this->root.'/config/services.php', <<<'PHPFILE'
<?php
use Illuminate\Support\{Env as GroupEnv, Str};
use Illuminate\Support\Str as Other, Illuminate\Support\Env as SecondEnv;

return [
    'one' => env(default: null, key: 'REORDERED_CONFIG'),
    'two' => GroupEnv::get(default: null, key: 'GROUPED_FACADE'),
    'three' => SecondEnv::get(key: 'SECOND_IMPORT'),
    'callable' => env(...),
];
PHPFILE);
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
use Illuminate\Support\Env as LaravelEnv;

$outside = env(default: null, key: 'REORDERED_OUTSIDE');
$facade = LaravelEnv::get(default: null, key: 'REORDERED_FACADE');
$dynamic = env(...$arguments);
$raw = getenv(local_only: true, name: 'NAMED_GETENV');
$getenvCallable = getenv(...);
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/config/services.php',
        $this->root.'/app/Example.php',
    ], $this->root.'/config');
    $keys = array_column($result['usages'], 'key');

    expect($keys)->toContain(
        'REORDERED_CONFIG',
        'GROUPED_FACADE',
        'SECOND_IMPORT',
        'REORDERED_OUTSIDE',
        'REORDERED_FACADE',
    )
        ->and($result['dynamic'])->toHaveCount(1)
        ->and($result['dynamic'][0]['source'])->toBe('env')
        ->and(array_column($result['raw'], 'key'))->toContain('NAMED_GETENV');
});

it('detects imported global env helper aliases', function () {
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
namespace App;

use function env as environment;
use function strlen as string_length;

$literal = environment('ALIASED_ENV');
$dynamic = environment($name);
$unrelated = string_length('value');
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect(array_column($result['usages'], 'key'))->toBe(['ALIASED_ENV'])
        ->and($result['usages'][0]['source'])->toBe('env')
        ->and($result['usages'][0]['in_config'])->toBeFalse()
        ->and($result['dynamic'])->toHaveCount(1)
        ->and($result['dynamic'][0]['source'])->toBe('env')
        ->and($result['raw'])->toBe([]);
});
