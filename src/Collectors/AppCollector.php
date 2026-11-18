<?php

namespace Tum\SystemReporter\Collectors;

use Illuminate\Database\Migrations\Migrator;
use Throwable;
use Tum\SystemReporter\Support\ErrorBag;
use Tum\SystemReporter\Support\GitVersion;

/**
 * Anwendung: Version, Framework, Abhängigkeitsstand, offene Migrationen.
 */
class AppCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(ErrorBag $errors): array
    {
        return array_filter([
            'name' => mb_substr((string) config('app.name'), 0, 150),
            'version' => $this->version(),
            'framework' => ['name' => 'laravel', 'version' => app()->version()],
            'dependency_fingerprint' => $this->fingerprint(),
            'pending_migrations' => $this->pendingMigrations($errors),
        ], fn ($v) => $v !== null);
    }

    public function version(): ?string
    {
        $configured = config('system-reporter.app_version');
        if (is_string($configured) && $configured !== '') {
            return mb_substr($configured, 0, 64);
        }

        return GitVersion::read(base_path());
    }

    /**
     * Fingerprint des installierten Abhängigkeitsstands. Ändert sich bei
     * jedem composer install/update, das Pakete tauscht.
     */
    public function fingerprint(): ?string
    {
        $parts = [];
        foreach (['vendor/composer/installed.json', 'package-lock.json'] as $file) {
            $path = base_path($file);
            $hash = @is_file($path) ? @hash_file('sha256', $path) : false;
            $parts[] = $hash === false ? '-' : $hash;
        }
        if ($parts === ['-', '-']) {
            return null;
        }

        return 'sha256:'.hash('sha256', implode('|', $parts));
    }

    private function pendingMigrations(ErrorBag $errors): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return null;
            }
            $paths = array_merge([database_path('migrations')], $migrator->paths());
            $files = array_keys($migrator->getMigrationFiles($paths));
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff($files, $ran));
        } catch (Throwable $e) {
            $errors->add('app.pending_migrations', class_basename($e));

            return null;
        }
    }
}
