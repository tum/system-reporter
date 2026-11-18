<?php

namespace Tum\SystemReporter\Tests\Unit;

use Tum\SystemReporter\Collectors\PackageCollector;
use Tum\SystemReporter\Support\ErrorBag;
use Tum\SystemReporter\Tests\TestCase;

class PackageCollectorTest extends TestCase
{
    public function test_composer_without_dev_and_npm_lockfile_v3(): void
    {
        $dir = $this->tempDir();
        mkdir($dir.'/vendor/composer', 0777, true);
        file_put_contents($dir.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v13.1.0', 'dist' => ['url' => 'https://secret.example/x']],
                ['name' => 'phpunit/phpunit', 'version' => '12.0.0'],
                ['name' => 'acme/branch', 'version' => 'dev-feature/JIRA-1234-implement-new-login-flow-for-external-users'],
            ],
            'dev' => true,
            'dev-package-names' => ['phpunit/phpunit'],
        ]));
        file_put_contents($dir.'/package-lock.json', json_encode([
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'app', 'version' => '1.0.0'],
                'node_modules/quill' => ['version' => '2.0.3'],
                'node_modules/vite' => ['version' => '7.0.0', 'dev' => true],
                'node_modules/quill/node_modules/@scope/lodash-es' => ['version' => '4.17.21'],
                'node_modules/other/node_modules/@scope/lodash-es' => ['version' => '4.17.21'],
                'node_modules/local' => ['resolved' => '../local', 'link' => true],
            ],
        ]));

        $errors = new ErrorBag;
        $packages = (new PackageCollector($dir))->collect($errors);

        $this->assertSame(['composer' => [['name' => 'laravel/framework', 'version' => 'v13.1.0']], 'npm' => [
            ['name' => '@scope/lodash-es', 'version' => '4.17.21'],
            ['name' => 'quill', 'version' => '2.0.3'],
        ]], $packages);
        $this->assertSame([], $errors->all());
    }

    public function test_composer_1_format_and_npm_lockfile_v1(): void
    {
        $dir = $this->tempDir();
        mkdir($dir.'/vendor/composer', 0777, true);
        file_put_contents($dir.'/vendor/composer/installed.json', json_encode([['name' => 'a/b', 'version' => '1.0.0']]));
        file_put_contents($dir.'/package-lock.json', json_encode([
            'lockfileVersion' => 1,
            'dependencies' => [
                'jquery' => ['version' => '3.7.1'],
                'mocha' => ['version' => '10.0.0', 'dev' => true],
                'gitdep' => ['version' => 'git+https://github.com/org/repo.git#'.str_repeat('a', 40)],
                'x' => ['version' => '1.0.0', 'dependencies' => ['y' => ['version' => '2.0.0']]],
            ],
        ]));

        $packages = (new PackageCollector($dir))->collect(new ErrorBag);

        $this->assertSame([['name' => 'a/b', 'version' => '1.0.0']], $packages['composer']);
        $this->assertSame(['jquery', 'x', 'y'], array_column($packages['npm'], 'name'));
    }

    public function test_missing_files_are_fine_broken_files_are_reported(): void
    {
        $dir = $this->tempDir();
        $this->assertSame([], (new PackageCollector($dir))->collect(new ErrorBag));

        file_put_contents($dir.'/package-lock.json', '{kaputt');
        $errors = new ErrorBag;
        (new PackageCollector($dir))->collect($errors);
        $this->assertSame(['packages.npm: package-lock.json not readable'], $errors->all());
    }
}
