<?php

namespace Tum\SystemReporter\Support;

/**
 * Liest den ausgecheckten Commit direkt aus .git — ohne git-Binary, das auf
 * Shared Hosting oft fehlt oder per disable_functions gesperrt ist.
 *
 * Berücksichtigt lose Refs, packed-refs (nach "git gc" oder frischem Clone),
 * detached HEAD und .git als Datei (Worktrees, Submodule).
 */
class GitVersion
{
    public static function read(string $basePath): ?string
    {
        $gitDir = self::gitDir($basePath);
        if ($gitDir === null) {
            return null;
        }

        $head = @file_get_contents($gitDir.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            return self::short($head);
        }

        $ref = trim(substr($head, 5));

        // Worktrees: Branch-Refs liegen im Haupt-Repo (commondir), lose wie gepackt.
        $dirs = array_values(array_filter([$gitDir, self::commonDir($gitDir)]));
        foreach ($dirs as $dir) {
            $loose = @file_get_contents($dir.'/'.$ref);
            if ($loose !== false) {
                return self::short(trim($loose));
            }
        }
        foreach ($dirs as $dir) {
            $packed = @file($dir.'/packed-refs', FILE_IGNORE_NEW_LINES);
            foreach ($packed ?: [] as $line) {
                if (preg_match('/^([0-9a-f]{40,64}) (.+)$/', $line, $m) && $m[2] === $ref) {
                    return self::short($m[1]);
                }
            }
        }

        return null;
    }

    private static function gitDir(string $basePath): ?string
    {
        $path = rtrim($basePath, '/').'/.git';
        if (@is_dir($path)) {
            return $path;
        }
        if (@is_file($path)) {
            $content = (string) @file_get_contents($path);
            if (preg_match('/^gitdir: (.+)$/m', $content, $m)) {
                $dir = trim($m[1]);

                return str_starts_with($dir, '/') ? $dir : rtrim($basePath, '/').'/'.$dir;
            }
        }

        return null;
    }

    private static function commonDir(string $gitDir): ?string
    {
        $common = @file_get_contents($gitDir.'/commondir');
        if ($common === false) {
            return null;
        }
        $common = trim($common);

        return str_starts_with($common, '/') ? $common : $gitDir.'/'.$common;
    }

    private static function short(string $sha): ?string
    {
        return preg_match('/^[0-9a-f]{40,64}$/', $sha) ? substr($sha, 0, 12) : null;
    }
}
