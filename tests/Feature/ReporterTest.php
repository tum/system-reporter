<?php

namespace Tum\SystemReporter\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tum\SystemReporter\Http\RecordWebRuntime;
use Tum\SystemReporter\Reporter;
use Tum\SystemReporter\SystemReporterServiceProvider;
use Tum\SystemReporter\Tests\TestCase;

class ReporterTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(RecordWebRuntime::path());
        parent::tearDown();
    }

    public function test_payload_matches_v2_and_has_no_identity_or_secrets(): void
    {
        config([
            'app.key' => 'base64:SECRETAPPKEYSECRETAPPKEY',
            'database.connections.testing.password' => 'SECRETDBPASSWORD',
            'system-reporter.app_version' => 'v1.2.3',
        ]);

        $payload = app(Reporter::class)->payload();
        $json = (string) json_encode($payload);

        $this->assertSame('tum.system-report/v2', $payload['schema']);
        $this->assertSame('tum/system-reporter', $payload['reporter']['name']);
        $this->assertSame('cli', $payload['reporter']['context']);
        $this->assertSame('v1.2.3', $payload['app']['version']);
        $this->assertSame('laravel', $payload['app']['framework']['name']);
        $this->assertSame(PHP_VERSION, $payload['runtime']['php']['version']);
        $this->assertSame(['debug' => false, 'https' => true, 'secure_cookie' => false], $payload['signals']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['reported_at']);

        $this->assertArrayNotHasKey('system_id', $payload);
        $this->assertArrayNotHasKey('owner', $payload);
        $this->assertStringNotContainsString('itps_testtoken', $json);
        $this->assertStringNotContainsString('SECRETAPPKEY', $json);
        $this->assertStringNotContainsString('SECRETDBPASSWORD', $json);
    }

    public function test_packages_can_be_switched_off(): void
    {
        $this->assertContains('packages', app(Reporter::class)->payload()['reporter']['capabilities']);

        config(['system-reporter.packages' => false]);
        $payload = app(Reporter::class)->payload();

        $this->assertNotContains('packages', $payload['reporter']['capabilities']);
        $this->assertArrayNotHasKey('packages', $payload);
    }

    public function test_push_sends_bearer_token_and_payload(): void
    {
        Http::fake(['portal.test/*' => Http::response(['status' => 'accepted', 'system_id' => 7, 'stored' => true], 202)]);

        $result = app(Reporter::class)->push();

        $this->assertSame(7, $result['system_id']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://portal.test/interface/systems/report'
            && $r->hasHeader('Authorization', 'Bearer itps_testtoken')
            && $r->hasHeader('Accept', 'application/json')
            && $r['schema'] === 'tum.system-report/v2');
    }

    public function test_client_errors_are_not_retried_and_explained(): void
    {
        Http::fake(['portal.test/*' => Http::response(['error' => 'validation_failed', 'errors' => ['schema' => ['invalid']]], 422)]);

        $this->artisan('system-reporter:report')
            ->expectsOutputToContain('HTTP 422')
            ->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_server_errors_are_retried(): void
    {
        Http::fakeSequence('portal.test/*')
            ->push('oops', 503)
            ->push(['status' => 'accepted', 'system_id' => 1, 'stored' => false], 202);

        $this->artisan('system-reporter:report')
            ->expectsOutputToContain('unverändert')
            ->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_missing_token_fails_clearly(): void
    {
        config(['system-reporter.token' => null]);
        Http::fake();

        $this->artisan('system-reporter:report')
            ->expectsOutputToContain('SYSTEM_REPORTER_TOKEN')
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_disabled_reporter_sends_nothing(): void
    {
        config(['system-reporter.enabled' => false]);
        Http::fake();

        $this->artisan('system-reporter:report')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_dry_run_prints_payload_without_sending(): void
    {
        Http::fake();

        $this->artisan('system-reporter:report', ['--dry-run' => true])
            ->expectsOutputToContain('tum.system-report/v2')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_schedule_uses_stable_spread_time(): void
    {
        $time = SystemReporterServiceProvider::scheduleTime();
        $this->assertMatchesRegularExpression('/^0[3-5]:[0-5]\d$/', $time);
        $this->assertSame($time, SystemReporterServiceProvider::scheduleTime());

        config(['system-reporter.schedule.time' => '04:17']);
        $this->assertSame('04:17', SystemReporterServiceProvider::scheduleTime());

        config(['system-reporter.schedule.time' => 'nonsense']);
        $this->assertSame($time, SystemReporterServiceProvider::scheduleTime());
    }

    public function test_command_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'system-reporter:report'));

        $this->assertCount(1, $events);
    }

    public function test_refuses_plain_http_except_localhost(): void
    {
        Http::fake();
        config(['system-reporter.url' => 'http://portal.it.tum.de/interface/systems/report']);

        $this->artisan('system-reporter:report')->expectsOutputToContain('https')->assertFailed();
        Http::assertNothingSent();

        $this->assertTrue(Reporter::isSafeUrl('http://localhost:8000/interface/systems/report'));
        $this->assertFalse(Reporter::isSafeUrl('ftp://portal.test/x'));
    }

    public function test_non_accepted_2xx_is_a_failure(): void
    {
        Http::fake(['portal.test/*' => Http::response('<html>Wartung</html>', 200)]);

        $this->artisan('system-reporter:report')
            ->expectsOutputToContain('Unerwartete Antwort')
            ->assertFailed();
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['portal.test/*' => Http::response('', 301, ['Location' => 'https://elsewhere.test/'])]);

        $this->artisan('system-reporter:report')->expectsOutputToContain('HTTP 301')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_invalid_utf8_does_not_break_sending(): void
    {
        config(['app.name' => "Lehrstuhl f\xfcr Test"]);
        Http::fake(['portal.test/*' => Http::response(['status' => 'accepted', 'system_id' => 1, 'stored' => true], 202)]);

        $this->artisan('system-reporter:report')->assertSuccessful();
    }

    public function test_stale_web_runtime_is_not_reported(): void
    {
        file_put_contents(RecordWebRuntime::path(), (string) json_encode(['version' => '8.1.0']));
        touch(RecordWebRuntime::path(), time() - 8 * 86400);

        $payload = app(Reporter::class)->payload();
        $this->assertArrayNotHasKey('web_php', $payload['runtime']);
        $this->assertContains('runtime.web_php: no recent web request recorded', $payload['collect_errors']);
    }

    public function test_web_runtime_is_recorded_and_reported(): void
    {
        Route::middleware('web')->get('/sr-probe', fn () => 'ok');

        $this->get('/sr-probe')->assertOk();

        $web = RecordWebRuntime::read();
        $this->assertNotNull($web);
        $this->assertSame(PHP_VERSION, $web['version']);

        $payload = app(Reporter::class)->payload();
        $this->assertSame(PHP_VERSION, $payload['runtime']['web_php']['version']);
        $this->assertArrayHasKey('display_errors', $payload['signals']);
        $this->assertArrayHasKey('expose_php', $payload['signals']);
    }
}
