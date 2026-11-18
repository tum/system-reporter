<?php

namespace Tum\SystemReporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tum\SystemReporter\Support\GitVersion;

class GitVersionTest extends TestCase
{
    private const SHA = '9380a5cf0123456789abcdef0123456789abcdef';

    private function repo(): string
    {
        $dir = sys_get_temp_dir().'/gv-'.bin2hex(random_bytes(6));
        mkdir($dir.'/.git/refs/heads', 0777, true);

        return $dir;
    }

    public function test_reads_loose_ref(): void
    {
        $dir = $this->repo();
        file_put_contents($dir.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($dir.'/.git/refs/heads/main', self::SHA."\n");

        $this->assertSame('9380a5cf0123', GitVersion::read($dir));
    }

    public function test_reads_packed_refs(): void
    {
        // Nach "git gc" oder frischem Clone gibt es keine lose Ref-Datei.
        $dir = $this->repo();
        file_put_contents($dir.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($dir.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n"
            .str_repeat('a', 40)." refs/heads/other\n".self::SHA." refs/heads/main\n");

        $this->assertSame('9380a5cf0123', GitVersion::read($dir));
    }

    public function test_reads_detached_head(): void
    {
        $dir = $this->repo();
        file_put_contents($dir.'/.git/HEAD', self::SHA."\n");

        $this->assertSame('9380a5cf0123', GitVersion::read($dir));
    }

    public function test_follows_gitdir_file(): void
    {
        $main = $this->repo();
        mkdir($main.'/.git/worktrees/wt', 0777, true);
        file_put_contents($main.'/.git/worktrees/wt/HEAD', "ref: refs/heads/feature\n");
        file_put_contents($main.'/.git/worktrees/wt/commondir', "../..\n");
        file_put_contents($main.'/.git/packed-refs', self::SHA." refs/heads/feature\n");

        $wt = sys_get_temp_dir().'/gv-wt-'.bin2hex(random_bytes(6));
        mkdir($wt);
        file_put_contents($wt.'/.git', 'gitdir: '.$main."/.git/worktrees/wt\n");

        $this->assertSame('9380a5cf0123', GitVersion::read($wt));
    }

    public function test_worktree_prefers_loose_ref_in_common_dir_over_stale_packed_ref(): void
    {
        $main = $this->repo();
        mkdir($main.'/.git/worktrees/wt', 0777, true);
        file_put_contents($main.'/.git/worktrees/wt/HEAD', "ref: refs/heads/feature\n");
        file_put_contents($main.'/.git/worktrees/wt/commondir', "../..\n");
        file_put_contents($main.'/.git/packed-refs', str_repeat('b', 40)." refs/heads/feature\n");
        file_put_contents($main.'/.git/refs/heads/feature', self::SHA."\n");

        $wt = sys_get_temp_dir().'/gv-wt-'.bin2hex(random_bytes(6));
        mkdir($wt);
        file_put_contents($wt.'/.git', 'gitdir: '.$main."/.git/worktrees/wt\n");

        $this->assertSame('9380a5cf0123', GitVersion::read($wt));
    }

    public function test_returns_null_for_unknown_ref_or_missing_repo(): void
    {
        $dir = $this->repo();
        file_put_contents($dir.'/.git/HEAD', "ref: refs/heads/main\n");

        $this->assertNull(GitVersion::read($dir));
        $this->assertNull(GitVersion::read(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4))));
    }
}
