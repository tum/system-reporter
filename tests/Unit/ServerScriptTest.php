<?php

namespace Tum\SystemReporter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * bin/tum-system-report: Payload aus einem Fixture-Root, Versand gegen den
 * PHP-Testserver, Fehlerfälle. Läuft nur auf Linux/macOS mit bash.
 */
class ServerScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/bash')) {
            $this->markTestSkipped('bash nötig');
        }
        $this->root = sys_get_temp_dir().'/sr-script-'.bin2hex(random_bytes(6));
        foreach (['etc', 'proc', 'var/run', 'var/log/apt', 'usr/share/update-notifier', 'bin'] as $dir) {
            mkdir($this->root.'/'.$dir, 0777, true);
        }
        file_put_contents($this->root.'/etc/os-release', "PRETTY_NAME=\"Ubuntu 24.04.1 LTS \\\"Noble\\\"\"\nID=ubuntu\nVERSION_ID=\"24.04\"\n");
        file_put_contents($this->root.'/etc/hostname', "web01.example.tum.de\n");
        file_put_contents($this->root.'/proc/uptime', "86400.55 1000.00\n");
        touch($this->root.'/var/run/reboot-required');
        touch($this->root.'/var/log/apt/history.log', 1_700_000_000);

        // Ersatz für apt-get: zwei Updates, eines davon aus security.
        file_put_contents($this->root.'/bin/apt-get', "#!/bin/sh\ncat <<'X'\nReading package lists...\nInst openssl [3.0.13-0ubuntu3.1] (3.0.13-0ubuntu3.4 Ubuntu:24.04/noble-security [amd64])\nInst curl [8.5.0-2ubuntu10.1] (8.5.0-2ubuntu10.6 Ubuntu:24.04/noble-updates [amd64])\nConf openssl\nX\n");
        chmod($this->root.'/bin/apt-get', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runScript(array $args, array $env = []): array
    {
        $env += [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TUM_SYSTEM_REPORT_ROOT' => $this->root,
            'TUM_SYSTEM_REPORT_APT_GET' => $this->root.'/bin/apt-get',
        ];
        $cmd = array_merge([dirname(__DIR__, 2).'/bin/tum-system-report'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function test_dry_run_payload(): void
    {
        $result = $this->runScript(['--dry-run']);
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tum.system-report/v2', $payload['schema']);
        $this->assertSame('tum/system-report.sh', $payload['reporter']['name']);
        $this->assertSame('web01.example.tum.de', $payload['instance']['hostname']);
        $this->assertSame('ubuntu', $payload['os']['id']);
        $this->assertSame('24.04', $payload['os']['version_id']);
        $this->assertSame('Ubuntu 24.04.1 LTS \"Noble\"', $payload['os']['pretty_name'], 'Anführungszeichen werden escaped');
        $this->assertSame(86400, $payload['os']['uptime_s']);
        $this->assertSame([
            'source' => 'apt',
            'pending' => 2,
            'security_pending' => 1,
            'reboot_required' => true,
            'last_upgrade_at' => '2023-11-14T22:13:20Z',
        ], $payload['os']['patch']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['reported_at']);
        $this->assertArrayNotHasKey('collect_errors', $payload);
    }

    public function test_failing_package_manager_is_reported_as_collect_error(): void
    {
        file_put_contents($this->root.'/bin/apt-get', "#!/bin/sh\nexit 100\n");

        $result = $this->runScript(['--dry-run']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('patch', $payload['os']);
        $this->assertSame(['os.patch: apt-get failed'], $payload['collect_errors']);
    }

    public function test_dnf_counts_packages_once(): void
    {
        // repoquery: eine Zeile je Paket, auch bei mehreren Architekturen doppelt
        file_put_contents($this->root.'/bin/dnf', <<<'SH'
#!/bin/sh
case "$*" in
  *repoquery*) printf 'kernel-core.x86_64\nopenssl-libs.x86_64\nopenssl-libs.x86_64\nvery-long-package-name-that-dnf-would-wrap.noarch\n' ;;
  *updateinfo*) if [ -n "$DNF5" ]; then
      printf 'Name Type Severity Package Issued\nRHSA-2026:1 security Important openssl-libs-3.0.7-28.el9.x86_64 2026-01-01\nRHSA-2026:2 security Moderate openssl-libs-3.0.7-28.el9.x86_64 2026-01-02\n'
    else
      printf 'RHSA-2026:1 Important/Sec. openssl-libs-1:3.0.7-28.el9.x86_64\nRHSA-2026:3 Moderate/Sec. kernel-core-5.14.0-503.el9.x86_64\n'
    fi ;;
  *) exit 1 ;;
esac
SH);
        chmod($this->root.'/bin/dnf', 0755);

        foreach (['' => 2, '1' => 1] as $dnf5 => $security) {
            $result = $this->runScript(['--dry-run'], [
                'TUM_SYSTEM_REPORT_APT_GET' => '',
                'TUM_SYSTEM_REPORT_DNF' => $this->root.'/bin/dnf',
                'DNF5' => (string) $dnf5,
            ]);
            $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('dnf', $payload['os']['patch']['source']);
            $this->assertSame(3, $payload['os']['patch']['pending']);
            $this->assertSame($security, $payload['os']['patch']['security_pending'], $dnf5 === '' ? 'dnf 4' : 'dnf 5');
        }
    }

    public function test_truncation_keeps_utf8_valid_without_lang(): void
    {
        file_put_contents($this->root.'/etc/os-release', 'PRETTY_NAME="'.str_repeat('a', 149).'äöü"'."\nID=debian\n");

        $result = $this->runScript(['--dry-run'], ['LANG' => '', 'LC_ALL' => '']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(str_repeat('a', 149).'ä', $payload['os']['pretty_name']);
    }

    public function test_refuses_missing_token_and_plain_http(): void
    {
        $result = $this->runScript([], ['TUM_SYSTEM_REPORT_TOKEN' => '']);
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('Kein Token', $result['stderr']);

        $result = $this->runScript([], ['TUM_SYSTEM_REPORT_TOKEN' => 'itps_x', 'TUM_SYSTEM_REPORT_URL' => 'http://portal.example.org/report']);
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('https', $result['stderr']);
    }

    public function test_pushes_with_token_from_config_and_checks_response(): void
    {
        if (! is_executable('/usr/bin/curl') && ! is_executable('/opt/homebrew/bin/curl')) {
            $this->markTestSkipped('curl nötig');
        }
        $port = random_int(20000, 40000);
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, dirname(__DIR__).'/fixtures/script-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['SR_CAPTURE_DIR' => $this->root],
        );
        $this->assertIsResource($server);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }

            file_put_contents($this->root.'/etc/tum-system-report.conf', "# Kommentar\nTOKEN=\"itps_good\"\nURL=http://127.0.0.1:{$port}/interface/systems/report\n");
            $result = $this->runScript(['--config', $this->root.'/etc/tum-system-report.conf']);
            $this->assertSame(0, $result['exit'], $result['stderr']);
            $this->assertStringContainsString('neuer Stand', $result['stdout']);

            $request = json_decode((string) file_get_contents($this->root.'/request.json'), true);
            $this->assertSame('POST', $request['method']);
            $this->assertSame('Bearer itps_good', $request['authorization']);
            $this->assertSame('application/json', $request['content_type']);
            $this->assertSame('tum.system-report/v2', json_decode($request['body'], true)['schema']);

            // Falsches Token: Exit 1 mit HTTP-Status
            $result = $this->runScript([], ['TUM_SYSTEM_REPORT_TOKEN' => 'itps_bad', 'TUM_SYSTEM_REPORT_URL' => "http://127.0.0.1:{$port}/x"]);
            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsString('HTTP 401', $result['stderr']);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
