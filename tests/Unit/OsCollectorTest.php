<?php

namespace Tum\SystemReporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tum\SystemReporter\Collectors\OsCollector;
use Tum\SystemReporter\Support\CommandRunner;
use Tum\SystemReporter\Support\ErrorBag;

class OsCollectorTest extends TestCase
{
    private function root(bool $withApt = true): string
    {
        $root = sys_get_temp_dir().'/os-'.bin2hex(random_bytes(6));
        foreach (['/etc', '/proc', '/usr/bin', '/var/run', '/var/log/apt'] as $dir) {
            mkdir($root.$dir, 0777, true);
        }
        file_put_contents($root.'/etc/os-release', "PRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\nID=debian\nVERSION_ID=\"12\"\n");
        file_put_contents($root.'/proc/uptime', "86400.55 1000.00\n");
        if ($withApt) {
            touch($root.'/usr/bin/apt-get');
        }

        return $root;
    }

    /**
     * @param  array{exit: int, output: list<string>}|null  $result
     */
    private function runner(?array $result): CommandRunner
    {
        return new class($result) extends CommandRunner
        {
            /** @param  array{exit: int, output: list<string>}|null  $result */
            public function __construct(private ?array $result) {}

            public function run(string $command): ?array
            {
                return $this->result;
            }
        };
    }

    public function test_reads_release_uptime_and_apt_state(): void
    {
        $root = $this->root();
        touch($root.'/var/run/reboot-required');
        touch($root.'/var/log/apt/history.log', 1763000000);

        $output = [
            'Reading package lists...',
            'Inst openssl [3.0.11-1~deb12u1] (3.0.13-1~deb12u1 Debian-Security:12/stable-security [amd64])',
            'Inst tzdata [2024a-0+deb12u1] (2024b-0+deb12u1 Debian:12.7/stable [all])',
            'Conf openssl (3.0.13-1~deb12u1 Debian-Security:12/stable-security [amd64])',
        ];
        $errors = new ErrorBag;
        $os = (new OsCollector($this->runner(['exit' => 0, 'output' => $output]), $root))->collect($errors, true);

        $this->assertSame('debian', $os['id']);
        $this->assertSame('12', $os['version_id']);
        $this->assertSame('Debian GNU/Linux 12 (bookworm)', $os['pretty_name']);
        $this->assertSame(86400, $os['uptime_s']);
        $this->assertSame([
            'source' => 'apt',
            'pending' => 2,
            'security_pending' => 1,
            'reboot_required' => true,
            'last_upgrade_at' => gmdate('Y-m-d\TH:i:s\Z', 1763000000),
        ], $os['patch']);
        $this->assertSame([], $errors->all());
    }

    public function test_reboot_state_is_unknown_without_update_notifier(): void
    {
        $runner = $this->runner(['exit' => 0, 'output' => []]);

        $root = $this->root();
        $os = (new OsCollector($runner, $root))->collect(new ErrorBag, true);
        $this->assertNull($os['patch']['reboot_required']);

        mkdir($root.'/usr/share/update-notifier', 0777, true);
        $os = (new OsCollector($runner, $root))->collect(new ErrorBag, true);
        $this->assertFalse($os['patch']['reboot_required']);
    }

    public function test_exec_disabled_is_reported_not_fatal(): void
    {
        $errors = new ErrorBag;
        $os = (new OsCollector($this->runner(null), $this->root()))->collect($errors, true);

        $this->assertNull($os['patch']);
        $this->assertSame(['os.patch: exec disabled'], $errors->all());
    }

    public function test_missing_apt_and_unreadable_release(): void
    {
        $root = $this->root(withApt: false);
        unlink($root.'/etc/os-release');

        $errors = new ErrorBag;
        $os = (new OsCollector($this->runner(['exit' => 0, 'output' => []]), $root))->collect($errors, true);

        $this->assertNull($os['patch']);
        $this->assertSame(['os.release: not readable', 'os.patch: unsupported package manager'], $errors->all());
    }

    public function test_patch_skipped_when_disabled(): void
    {
        $errors = new ErrorBag;
        $os = (new OsCollector($this->runner(null), $this->root()))->collect($errors, false);

        $this->assertArrayNotHasKey('patch', $os);
        $this->assertSame([], $errors->all());
    }
}
