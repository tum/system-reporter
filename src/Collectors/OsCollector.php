<?php

namespace Tum\SystemReporter\Collectors;

use Tum\SystemReporter\Support\CommandRunner;
use Tum\SystemReporter\Support\ErrorBag;

/**
 * Betriebssystem und Patchstand — alles ohne Root-Rechte.
 *
 * Ausstehende Updates ermittelt "apt-get -s upgrade" (Simulation, braucht
 * keine Sperre und kein Root). Andere Paketmanager meldet v2 noch nicht.
 */
class OsCollector
{
    /**
     * @param  string  $root  Präfix für Systempfade (Tests zeigen auf Fixtures)
     */
    public function __construct(
        private CommandRunner $runner,
        private string $root = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(ErrorBag $errors, bool $withPatch): array
    {
        $os = [
            'family' => PHP_OS_FAMILY,
            'kernel' => php_uname('r'),
            'machine' => php_uname('m'),
        ];

        $release = @file_get_contents($this->root.'/etc/os-release');
        if ($release === false) {
            $errors->add('os.release', 'not readable');
        } else {
            foreach (['ID' => 'id', 'VERSION_ID' => 'version_id', 'PRETTY_NAME' => 'pretty_name'] as $key => $field) {
                if (preg_match('/^'.$key.'="?([^"\n]*)"?$/m', $release, $m)) {
                    $os[$field] = $m[1];
                }
            }
        }

        $uptime = @file_get_contents($this->root.'/proc/uptime');
        if ($uptime !== false && preg_match('/^(\d+)/', $uptime, $m)) {
            $os['uptime_s'] = (int) $m[1];
        }

        if ($withPatch) {
            $os['patch'] = $this->patch($errors, $os['id'] ?? null);
        }

        return $os;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function patch(ErrorBag $errors, ?string $osId): ?array
    {
        if (PHP_OS_FAMILY !== 'Linux' && $this->root === '') {
            $errors->add('os.patch', 'not linux');

            return null;
        }
        if (! is_file($this->root.'/usr/bin/apt-get')) {
            $errors->add('os.patch', 'unsupported package manager'.($osId ? ' ('.$osId.')' : ''));

            return null;
        }

        $result = $this->runner->run('LC_ALL=C apt-get -s -o Debug::NoLocking=1 upgrade');
        if ($result === null) {
            $errors->add('os.patch', 'exec disabled');

            return null;
        }
        if ($result['exit'] !== 0) {
            $errors->add('os.patch', 'apt-get exit '.$result['exit']);

            return null;
        }

        $pending = 0;
        $security = 0;
        foreach ($result['output'] as $line) {
            if (! str_starts_with($line, 'Inst ')) {
                continue;
            }
            $pending++;
            // "Inst openssl [3.0.11-1] (3.0.13-1 Debian-Security:12/stable-security [amd64])"
            if (preg_match('/security/i', $line)) {
                $security++;
            }
        }

        $patch = [
            'source' => 'apt',
            'pending' => $pending,
            'security_pending' => $security,
            'reboot_required' => is_file($this->root.'/var/run/reboot-required'),
        ];

        foreach (['/var/log/apt/history.log', '/var/lib/dpkg/status'] as $file) {
            $mtime = @filemtime($this->root.$file);
            if ($mtime !== false) {
                $patch['last_upgrade_at'] = gmdate('Y-m-d\TH:i:s\Z', $mtime);
                break;
            }
        }

        return $patch;
    }
}
