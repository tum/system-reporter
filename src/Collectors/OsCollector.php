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
        $os = ['family' => PHP_OS_FAMILY];

        // php_uname steht auf vielen Shared Hosts in disable_functions; ein
        // Aufruf wirft dann seit PHP 8 einen Error statt false zu liefern.
        if (function_exists('php_uname')) {
            $os['kernel'] = mb_substr(php_uname('r'), 0, 100);
            $os['machine'] = php_uname('m');
        } else {
            $errors->add('os.kernel', 'php_uname disabled');
        }

        $release = @file_get_contents($this->root.'/etc/os-release');
        if ($release === false) {
            $errors->add('os.release', 'not readable');
        } else {
            foreach (['ID' => 'id', 'VERSION_ID' => 'version_id', 'PRETTY_NAME' => 'pretty_name'] as $key => $field) {
                if (preg_match('/^'.$key.'="?([^"\n]*)"?$/m', $release, $m)) {
                    $os[$field] = mb_substr($m[1], 0, $field === 'pretty_name' ? 150 : 50);
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
        // @: mit open_basedir wirft is_file() eine Warnung, die Laravel zur
        // Exception macht — und der ganze Report ginge verloren.
        if (! @is_file($this->root.'/usr/bin/apt-get')) {
            $errors->add('os.patch', 'unsupported package manager'.($osId ? ' ('.$osId.')' : ''));

            return null;
        }

        // dist-upgrade statt upgrade: Kernel-Sicherheitsupdates kommen auf
        // Ubuntu als neue Pakete und würden bei "upgrade" zurückgehalten.
        $result = $this->runner->run('LC_ALL=C apt-get -s -o Debug::NoLocking=1 dist-upgrade');
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
            'reboot_required' => $this->rebootRequired(),
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

    /**
     * /var/run/reboot-required legt nur update-notifier an (Ubuntu, auf
     * Debian optional). Ohne diesen Mechanismus heißt "keine Datei" nicht
     * "kein Neustart nötig", sondern "unbekannt".
     */
    private function rebootRequired(): ?bool
    {
        if (@is_file($this->root.'/var/run/reboot-required')) {
            return true;
        }

        return @is_dir($this->root.'/usr/share/update-notifier') ? false : null;
    }
}
