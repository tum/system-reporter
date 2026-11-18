<?php

namespace Tum\SystemReporter\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tum\SystemReporter\SystemReporterServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SystemReporterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://app.example.tum.de');
        $app['config']->set('app.name', 'Test-App');
        $app['config']->set('app.debug', false);
        $app['config']->set('system-reporter.enabled', true);
        $app['config']->set('system-reporter.token', 'itps_testtoken');
        $app['config']->set('system-reporter.url', 'https://portal.test/interface/systems/report');
        $app['config']->set('system-reporter.os_patch', false);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/sr-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
