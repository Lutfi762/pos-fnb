<?php

declare(strict_types=1);

return [
    // Where `php artisan meja:backup` writes. Put this on another disk (or a
    // synced folder) if you can: a backup next to the database dies with it.
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    // How many backups to keep; older ones are deleted after each run.
    'keep' => (int) env('BACKUP_KEEP', 14),

    // mysqldump is not always on the PATH of a scheduled task. On Laragon it
    // lives in C:\laragon\bin\mysql\mysql-<versi>\bin\mysqldump.exe.
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    // Seconds the dump may take before it is treated as failed.
    'timeout' => (int) env('BACKUP_TIMEOUT', 600),
];
