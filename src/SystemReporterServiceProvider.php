<?php

namespace Tum\SystemReporter;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Tum\SystemReporter\Collectors\OsCollector;
use Tum\SystemReporter\Console\ReportCommand;
use Tum\SystemReporter\Http\RecordWebRuntime;
use Tum\SystemReporter\Support\CommandRunner;

class SystemReporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/system-reporter.php', 'system-reporter');
        $this->app->singleton(Reporter::class);
        $this->app->bind(OsCollector::class, fn ($app) => new OsCollector($app->make(CommandRunner::class)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/system-reporter.php' => config_path('system-reporter.php'),
            ], 'system-reporter-config');

            $this->commands([ReportCommand::class]);
        }

        if (! config('system-reporter.enabled')) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('system-reporter.schedule.enabled')) {
                return;
            }
            $schedule->command('system-reporter:report')
                ->dailyAt(self::scheduleTime())
                ->timezone((string) config('system-reporter.schedule.timezone', 'Europe/Berlin'))
                ->withoutOverlapping(30);
        });

        // Über den HTTP-Kernel, nicht den Router: der Kernel überschreibt
        // die Router-Gruppen, wenn er erst nach diesem Boot entsteht.
        if (config('system-reporter.record_web_runtime')) {
            /** @var mixed $kernel Apps können einen eigenen Kernel binden. */
            $kernel = $this->app->make(HttpKernel::class);
            if ($kernel instanceof Kernel) {
                $kernel->appendMiddlewareToGroup('web', RecordWebRuntime::class);
            }
        }
    }

    /**
     * Feste Zeit aus der Config, sonst eine stabile Minute zwischen 02:00
     * und 05:59 je Installation — verteilt die Last auf dem Portal.
     */
    public static function scheduleTime(): string
    {
        $configured = config('system-reporter.schedule.time');
        if (is_string($configured) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $configured)) {
            return $configured;
        }

        $minutes = crc32(config('app.url').'|'.config('app.name')) % 240;

        return sprintf('%02d:%02d', 2 + intdiv($minutes, 60), $minutes % 60);
    }
}
