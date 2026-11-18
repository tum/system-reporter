<?php

namespace Tum\SystemReporter\Console;

use Illuminate\Console\Command;
use Throwable;
use Tum\SystemReporter\Reporter;

class ReportCommand extends Command
{
    protected $signature = 'system-reporter:report
        {--dry-run : Payload nur anzeigen, nichts senden}';

    protected $description = 'Meldet System- und Patchstand an das IT-Inventar des TUM IT-Portals';

    public function handle(Reporter $reporter): int
    {
        if ($this->option('dry-run')) {
            $this->line((string) json_encode($reporter->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (! config('system-reporter.enabled')) {
            $this->warn('System-Reporter ist deaktiviert (SYSTEM_REPORTER_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $result = $reporter->push();
        } catch (Throwable $e) {
            report($e);
            $this->error('Meldung fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Gemeldet (System %s, %s).',
            $result['system_id'] ?? '?',
            ($result['stored'] ?? false) ? 'neuer Stand gespeichert' : 'unverändert'
        ));

        return self::SUCCESS;
    }
}
