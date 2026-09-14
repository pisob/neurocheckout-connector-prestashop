<?php

declare(strict_types=1);

namespace NeuroCheckout\Community;

use RuntimeException;

/** Durable synchronization state: never keep cursors in a disposable cache. */
final class PrestashopSourceDirectory
{
    public static function resolve(string $webRoot, string $legacy): string
    {
        if (realpath($webRoot) !== $webRoot || !is_dir($webRoot)) { self::fail(); }
        $parent = $webRoot . '/var';
        if (!file_exists($parent) && !is_link($parent) && !@mkdir($parent, 0700)) { self::fail(); }
        if (realpath($parent) !== $parent || !is_dir($parent)) { self::fail(); }
        $target = $parent . '/neurocheckout-community-source';
        $lockPath = $parent . '/.neurocheckout-community-source-migration.lock';
        if (!file_exists($lockPath) && !is_link($lockPath)) {
            $created = @fopen($lockPath, 'x+b');
            if ($created) { chmod($lockPath, 0600); fclose($created); }
        }
        self::privatePath($lockPath, false);
        $lock = @fopen($lockPath, 'r+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            self::fail();
        }
        $held = [];
        try {
            if (file_exists($target) || is_link($target)) {
                self::privatePath($target, true);
            } else {
                // Losing an already initialized durable directory is not a new install.
                if (stream_get_contents($lock) !== '') { self::fail(); }
                if (file_exists($legacy) || is_link($legacy)) {
                    self::privatePath($legacy, true);
                    if (realpath($legacy) !== $legacy || $legacy === $target) { self::fail(); }
                    $names = self::entries($legacy);
                    foreach ($names as $name) {
                        self::privatePath($legacy . '/' . $name, false);
                        if (substr($name, -5) !== '.lock') { continue; }
                        $handle = @fopen($legacy . '/' . $name, 'r+b');
                        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
                            if ($handle) { fclose($handle); }
                            self::fail();
                        }
                        $held[] = $handle;
                    }
                    if ($names !== self::entries($legacy)) { self::fail(); }
                    // Same filesystem only: rename preserves bytes, permissions and lock inodes.
                    if (stat($legacy)['dev'] !== stat($parent)['dev']) { self::fail(); }
                    self::protect($legacy);
                    if (!@rename($legacy, $target)) { self::fail(); }
                } else {
                    if (!@mkdir($target, 0700) || !@chmod($target, 0700)) { self::fail(); }
                }
            }
            self::protect($target);
            rewind($lock);
            if (fwrite($lock, "ready\n") !== 6 || !fflush($lock)) { self::fail(); }
            if (function_exists('fsync') && !fsync($lock)) { self::fail(); }
            return $target;
        } finally {
            foreach ($held as $handle) { flock($handle, LOCK_UN); fclose($handle); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private static function entries(string $path): array
    {
        $names = @scandir($path);
        if (!is_array($names) || count($names) > 258) { self::fail(); }
        return array_values(array_diff($names, ['.', '..']));
    }

    private static function protect(string $directory): void
    {
        self::privatePath($directory, true);
        foreach ([
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php' => "<?php http_response_code(404); exit;\n",
        ] as $name => $content) {
            $path = $directory . '/' . $name;
            if (!file_exists($path) && !is_link($path)) {
                $file = @fopen($path, 'x+b');
                if (!$file) { self::fail(); }
                try {
                    if (!chmod($path, 0600) || fwrite($file, $content) !== strlen($content) || !fflush($file)) { self::fail(); }
                    if (function_exists('fsync') && !fsync($file)) { self::fail(); }
                } finally { fclose($file); }
            }
            self::privatePath($path, false);
            if (file_get_contents($path) !== $content) { self::fail(); }
        }
    }

    private static function privatePath(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || is_link($path) || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0077) !== 0 || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
            self::fail();
        }
    }

    private static function fail(): void
    {
        throw new RuntimeException('source_unavailable');
    }
}
