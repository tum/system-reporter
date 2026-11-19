<?php

namespace Tum\SystemReporter;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tum\SystemReporter\Collectors\AppCollector;
use Tum\SystemReporter\Collectors\OsCollector;
use Tum\SystemReporter\Collectors\PackageCollector;
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

    public const VERSION = '2.2.0';

    public function __construct(
        private AppCollector $app,
        private RuntimeCollector $runtime,
        private OsCollector $os,
        private PackageCollector $packages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $errors = new ErrorBag;
        $withPatch = (bool) config('system-reporter.os_patch', true);
        $withPackages = (bool) config('system-reporter.packages', true);

        $payload = [
            'schema' => self::SCHEMA,
            'reported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'reporter' => [
                'name' => 'tum/system-reporter',
                'version' => self::VERSION,
                'context' => PHP_SAPI === 'cli' ? 'cli' : 'web',
                'capabilities' => array_values(array_filter([
                    'app', 'runtime', 'os', $withPatch ? 'os_patch' : null,
                    $withPackages ? 'packages' : null,
                    config('system-reporter.record_web_runtime') ? 'web_runtime' : null,
                ])),
            ],
            'instance' => array_filter([
                'hostname' => function_exists('gethostname') ? (mb_substr((string) gethostname(), 0, 255) ?: null) : null,
                'environment' => mb_substr((string) app()->environment(), 0, 20),
                'base_url' => mb_substr((string) config('app.url'), 0, 255) ?: null,
            ]),
            'app' => $this->app->collect($errors),
            'runtime' => $this->runtime->collect($errors),
            'os' => $this->os->collect($errors, $withPatch),
            'signals' => $this->signals(),
        ];

        if ($withPackages) {
            $packages = $this->packages->collect($errors);
            if ($packages !== []) {
                $payload['packages'] = $packages;
            }
        }

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
        if (! self::isSafeUrl($url)) {
            throw new RuntimeException('SYSTEM_REPORTER_URL muss https sein (http nur für localhost) — sonst ginge das Token im Klartext übers Netz.');
        }

        // Ungültiges UTF-8 (z. B. APP_NAME aus einer Latin-1-.env) ersetzen,
        // statt beim Senden an json_encode zu scheitern.
        $payload = json_decode((string) json_encode($this->payload(), JSON_INVALID_UTF8_SUBSTITUTE), true);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('system-reporter.http_timeout', 15))
                // Ein Redirect machte aus dem POST ein GET ohne Body.
                ->withoutRedirecting()
                // Nur Verbindungsfehler und 5xx erneut versuchen; 401/422
                // ändern sich durch Wiederholen nicht.
                ->retry(3, 2000, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Portal nicht erreichbar: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->describeFailure($response));
        }

        // Ein 2xx von einem Proxy oder einer Fehlerseite ist keine Annahme.
        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? null) !== 'accepted') {
            throw new RuntimeException('Unerwartete Antwort (HTTP '.$response->status().'): '.mb_substr(trim(strip_tags($response->body())), 0, 200));
        }

        return $body;
    }

    public static function isSafeUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true));
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
