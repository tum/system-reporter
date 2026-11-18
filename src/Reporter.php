<?php

namespace Tum\SystemReporter;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tum\SystemReporter\Collectors\AppCollector;
use Tum\SystemReporter\Collectors\OsCollector;
use Tum\SystemReporter\Collectors\RuntimeCollector;
use Tum\SystemReporter\Http\RecordWebRuntime;
use Tum\SystemReporter\Support\ErrorBag;

/**
 * Baut den Heartbeat im Format tum.system-report/v2 und schickt ihn an das
 * IT-Portal. Das Portal erkennt das System am Token — der Payload enthält
 * keine System-ID und keine Zuständigkeiten.
 */
class Reporter
{
    public const SCHEMA = 'tum.system-report/v2';

    public const VERSION = '2.0.0';

    public function __construct(
        private AppCollector $app,
        private RuntimeCollector $runtime,
        private OsCollector $os,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $errors = new ErrorBag;
        $withPatch = (bool) config('system-reporter.os_patch', true);

        $payload = [
            'schema' => self::SCHEMA,
            'reported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'reporter' => [
                'name' => 'tum/system-reporter',
                'version' => self::VERSION,
                'context' => PHP_SAPI === 'cli' ? 'cli' : 'web',
                'capabilities' => array_values(array_filter([
                    'app', 'runtime', 'os', $withPatch ? 'os_patch' : null,
                    config('system-reporter.record_web_runtime') ? 'web_runtime' : null,
                ])),
            ],
            'instance' => array_filter([
                'hostname' => gethostname() ?: null,
                'environment' => app()->environment(),
                'base_url' => (string) config('app.url') ?: null,
            ]),
            'app' => $this->app->collect($errors),
            'runtime' => $this->runtime->collect($errors),
            'os' => $this->os->collect($errors, $withPatch),
            'signals' => $this->signals(),
        ];

        if ($errors->all() !== []) {
            $payload['collect_errors'] = $errors->all();
        }

        return $payload;
    }

    /**
     * Sicherheitsrelevante Konfiguration als Ja/Nein — nie Werte.
     * display_errors/expose_php nur aus dem Web-Kontext: in der CLI ist
     * display_errors meist an und würde falschen Alarm auslösen.
     *
     * @return array<string, bool>
     */
    private function signals(): array
    {
        $signals = [
            'debug' => (bool) config('app.debug'),
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'secure_cookie' => (bool) config('session.secure'),
        ];

        $web = RecordWebRuntime::read();
        if ($web !== null) {
            $signals['display_errors'] = (bool) ($web['display_errors'] ?? false);
            $signals['expose_php'] = (bool) ($web['expose_php'] ?? false);
        }

        return $signals;
    }

    /**
     * Schickt den Heartbeat. Wirft bei Fehlern mit einer Meldung ohne Token.
     *
     * @return array<string, mixed> Antwort des Portals
     */
    public function push(): array
    {
        $url = (string) config('system-reporter.url');
        $token = (string) config('system-reporter.token');
        if ($url === '' || $token === '') {
            throw new RuntimeException('SYSTEM_REPORTER_URL oder SYSTEM_REPORTER_TOKEN ist nicht gesetzt.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('system-reporter.http_timeout', 15))
                // Nur Verbindungsfehler und 5xx erneut versuchen; 401/422
                // ändern sich durch Wiederholen nicht.
                ->retry(3, 2000, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->post($url, $this->payload());
        } catch (ConnectionException $e) {
            throw new RuntimeException('Portal nicht erreichbar: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->describeFailure($response));
        }

        return (array) $response->json();
    }

    private function describeFailure(Response $response): string
    {
        $body = $response->json();
        $detail = match (true) {
            is_array($body) && isset($body['errors']) => json_encode($body['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_array($body) && isset($body['error']) => (string) $body['error'],
            default => mb_substr(trim(strip_tags($response->body())), 0, 200),
        };

        return 'Portal antwortete mit HTTP '.$response->status().': '.$detail;
    }
}
