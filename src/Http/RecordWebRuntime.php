<?php

namespace Tum\SystemReporter\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merkt sich einmal am Tag, mit welcher PHP-Version und welchen
 * ini-Einstellungen die Anwendung im Web läuft. Der Report entsteht per
 * Cron in der CLI — auf Shared Hosting oft eine andere PHP-Version.
 *
 * Kosten pro Request: ein stat() auf eine kleine Datei.
 */
class RecordWebRuntime
{
    private const MAX_AGE = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $file = self::path();
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime < time() - self::MAX_AGE) {
                self::write($file);
            }
        } catch (\Throwable) {
            // Nie eine Anfrage wegen des Reporters scheitern lassen.
        }

        return $response;
    }

    public static function path(): string
    {
        return storage_path('framework/system-reporter-web.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        $raw = @file_get_contents(self::path());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private static function write(string $file): void
    {
        $data = [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'display_errors' => filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN),
            'expose_php' => filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN),
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        // Atomar ersetzen: parallele Requests lesen nie eine halbe Datei.
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
            @rename($tmp, $file);
        }
    }
}
