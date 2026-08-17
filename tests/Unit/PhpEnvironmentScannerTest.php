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
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/config/services.php',
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect($result['usages'])->toHaveCount(2)
        ->and($result['usages'][0]['in_config'])->toBeTrue()
        ->and($result['usages'][1]['in_config'])->toBeFalse()
        ->and($result['dynamic'])->toHaveCount(1)
        ->and($result['dynamic'][0]['in_config'])->toBeFalse();
});

it('detects imported Env facade and raw environment access', function () {
    file_put_contents($this->root.'/app/Example.php', <<<'PHPFILE'
<?php
use Illuminate\Support\Env;

$one = Env::get('ONE');
$two = getenv('TWO');
$three = $_ENV['THREE'];
$four = $_SERVER["FOUR"];
PHPFILE);

    $result = (new PhpEnvironmentScanner)->scan([
        $this->root.'/app/Example.php',
    ], $this->root.'/config');

    expect(array_column($result['usages'], 'key'))->toContain('ONE')
        ->and(array_column($result['raw'], 'key'))->toBe(['TWO', 'THREE', 'FOUR']);
});
