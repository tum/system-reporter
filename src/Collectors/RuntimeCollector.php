<?php

namespace Tum\SystemReporter\Collectors;

use Illuminate\Support\Facades\DB;
use Throwable;
use Tum\SystemReporter\Http\RecordWebRuntime;
use Tum\SystemReporter\Support\ErrorBag;

/**
 * PHP, Datenbank und Bibliotheken. Keine Secrets, keine personenbezogenen
 * Daten.
 */
class RuntimeCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(ErrorBag $errors): array
    {
        $runtime = [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'extensions' => $this->extensions(),
            ],
            'libraries' => array_filter([
                'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
                'curl' => function_exists('curl_version') ? (curl_version()['version'] ?? null) : null,
                'icu' => defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : null,
            ]),
        ];

        $runtime['database'] = $this->database($errors);

        $web = RecordWebRuntime::read();
        if ($web !== null) {
            $runtime['web_php'] = $web;
        } elseif (config('system-reporter.record_web_runtime')) {
            $errors->add('runtime.web_php', 'no recent web request recorded');
        }

        return $runtime;
    }

    /**
     * @return array<string, string|null>
     */
    private function extensions(): array
    {
        $result = [];
        foreach (get_loaded_extensions() as $ext) {
            $result[strtolower($ext)] = phpversion($ext) ?: null;
        }
        ksort($result);

        return $result;
    }

    /**
     * @return array{product: string, version: string}|null
     */
    private function database(ErrorBag $errors): ?array
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $raw = (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $e) {
            $errors->add('runtime.database', class_basename($e));

            return null;
        }

        return self::normalizeDatabase($driver, $raw);
    }

    /**
     * MariaDB meldet sich über das MySQL-Protokoll als "5.5.5-10.11.6-MariaDB-…".
     *
     * @return array{product: string, version: string}
     */
    public static function normalizeDatabase(string $driver, string $raw): array
    {
        if (stripos($raw, 'mariadb') !== false || $driver === 'mariadb') {
            $version = preg_replace('/^5\.5\.5-/', '', $raw) ?? $raw;
            preg_match('/^\d+(\.\d+){0,2}/', $version, $m);

            return ['product' => 'mariadb', 'version' => mb_substr($m[0] ?? $version, 0, 50)];
        }

        $product = match ($driver) {
            'pgsql' => 'postgresql',
            'sqlsrv' => 'sqlserver',
            default => $driver,
        };
        preg_match('/^\d+(\.\d+){0,2}/', $raw, $m);

        return ['product' => mb_substr($product, 0, 30), 'version' => mb_substr($m[0] ?? $raw, 0, 50)];
    }
}
