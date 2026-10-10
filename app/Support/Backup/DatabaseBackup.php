<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A compressed copy of the whole database, and the rotation that keeps the
 * folder from filling the disk.
 *
 * MySQL and MariaDB go through mysqldump in one transaction, so tills can
 * keep selling while it runs. SQLite (tests, a single laptop) uses
 * `VACUUM INTO`, which writes a consistent copy without locking writers out
 * for long.
 */
class DatabaseBackup
{
    public const PREFIX = 'meja-';

    /**
     * Write one backup and return its full path.
     */
    public function run(?string $connection = null): string
    {
        $connection ??= (string) config('database.default');

        /** @var array<string, mixed> $config */
        $config = config('database.connections.'.$connection);
        $driver = (string) ($config['driver'] ?? '');

        $directory = $this->directory();
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Ymd-His');

        $raw = match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($config, $directory.DIRECTORY_SEPARATOR.self::PREFIX.$stamp.'.sql'),
            'sqlite' => $this->copySqlite($connection, $directory.DIRECTORY_SEPARATOR.self::PREFIX.$stamp.'.sqlite'),
            default => throw new RuntimeException(__('backup.unsupported', ['driver' => $driver])),
        };

        return $this->compress($raw);
    }

    /**
     * Delete all but the newest backups.
     *
     * @return int how many were deleted
     */
    public function prune(?int $keep = null): int
    {
        $keep = max(1, $keep ?? (int) config('backup.keep'));
        $old = array_slice($this->list(), $keep);

        foreach ($old as $path) {
            File::delete($path);
        }

        return count($old);
    }

    /**
     * Backups on disk, newest first.
     *
     * @return list<string>
     */
    public function list(): array
    {
        $files = File::glob($this->directory().DIRECTORY_SEPARATOR.self::PREFIX.'*.gz');

        // The timestamp is in the name, so names sort by age.
        rsort($files);

        return $files;
    }

    public function directory(): string
    {
        return rtrim((string) config('backup.path'), '/\\');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpMysql(array $config, string $target): string
    {
        $command = [
            (string) config('backup.mysqldump'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? '3306'),
            '--user='.($config['username'] ?? 'root'),
            '--result-file='.$target,
            (string) ($config['database'] ?? ''),
        ];

        // The password travels in the environment, not on the command line
        // where any process listing would show it.
        $result = Process::timeout((int) config('backup.timeout'))
            ->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->run($command);

        if ($result->failed() || ! File::exists($target)) {
            File::delete($target);

            throw new RuntimeException(__('backup.dump_failed', ['error' => trim($result->errorOutput()) ?: $result->exitCode()]));
        }

        return $target;
    }

    private function copySqlite(string $connection, string $target): string
    {
        DB::connection($connection)->statement('VACUUM INTO ?', [$target]);

        if (! File::exists($target)) {
            throw new RuntimeException(__('backup.dump_failed', ['error' => 'VACUUM INTO']));
        }

        return $target;
    }

    /**
     * Gzip a file in chunks, then remove the uncompressed copy.
     */
    private function compress(string $source): string
    {
        $target = $source.'.gz';

        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        if ($in === false || $out === false) {
            throw new RuntimeException(__('backup.dump_failed', ['error' => 'gzip']));
        }

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 512);

            if ($chunk !== false && $chunk !== '') {
                gzwrite($out, $chunk);
            }
        }

        fclose($in);
        gzclose($out);
        File::delete($source);

        return $target;
    }
}
