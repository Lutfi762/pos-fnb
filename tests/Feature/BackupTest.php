<?php

declare(strict_types=1);

use App\Support\Backup\DatabaseBackup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->folder = sys_get_temp_dir().'/meja-backup-test-'.bin2hex(random_bytes(4));
    config(['backup.path' => $this->folder, 'backup.keep' => 3]);
});

afterEach(function (): void {
    File::deleteDirectory($this->folder);
});

it('copies a SQLite database into a gzip that opens again', function (): void {
    // A file database on its own connection: the test database sits inside
    // a transaction, where SQLite refuses VACUUM.
    $source = $this->folder.'/source.sqlite';
    File::ensureDirectoryExists($this->folder);
    touch($source);

    config(['database.connections.backup_source' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => true]]);

    DB::connection('backup_source')->statement('create table bills (id integer primary key, total integer)');
    DB::connection('backup_source')->table('bills')->insert([['total' => 45_000], ['total' => 12_500]]);

    $path = app(DatabaseBackup::class)->run('backup_source');

    expect($path)->toEndWith('.sqlite.gz')
        ->and(File::exists($path))->toBeTrue()
        ->and(File::exists(substr($path, 0, -3)))->toBeFalse();

    $restored = $this->folder.'/restored.sqlite';
    file_put_contents($restored, gzdecode((string) file_get_contents($path)));

    $pdo = new PDO('sqlite:'.$restored);

    expect((int) $pdo->query('select sum(total) from bills')->fetchColumn())->toBe(57_500);
});

it('dumps MySQL in one transaction without the password on the command line', function (): void {
    config(['database.connections.mysql.password' => 'rahasia', 'database.connections.mysql.database' => 'meja']);

    Process::fake(function (PendingProcess $process) {
        $target = collect($process->command)->first(fn (string $part): bool => str_starts_with($part, '--result-file='));
        file_put_contents(substr((string) $target, strlen('--result-file=')), "CREATE TABLE orders (id char(26));\n");

        return Process::result();
    });

    $path = app(DatabaseBackup::class)->run('mysql');

    expect(gzdecode((string) file_get_contents($path)))->toContain('CREATE TABLE orders');

    Process::assertRan(function (PendingProcess $process): bool {
        return in_array('--single-transaction', $process->command, true)
            && end($process->command) === 'meja'
            && ! in_array('rahasia', $process->command, true)
            && collect($process->command)->every(fn (string $part): bool => ! str_contains($part, 'rahasia'))
            && $process->environment['MYSQL_PWD'] === 'rahasia';
    });
});

it('reports a failed dump instead of keeping an empty file', function (): void {
    Process::fake(fn () => Process::result(errorOutput: 'Access denied', exitCode: 2));

    $this->artisan('meja:backup', ['--connection' => 'mysql'])
        ->expectsOutputToContain('Access denied')
        ->assertFailed();

    expect(app(DatabaseBackup::class)->list())->toBe([]);
});

it('keeps only the newest backups', function (): void {
    File::ensureDirectoryExists($this->folder);

    foreach (['20261001-043000', '20261002-043000', '20261003-043000', '20261004-043000', '20261005-043000'] as $stamp) {
        file_put_contents($this->folder.'/meja-'.$stamp.'.sql.gz', 'x');
    }

    // Not ours: never touched by the rotation.
    file_put_contents($this->folder.'/catatan.txt', 'x');

    expect(app(DatabaseBackup::class)->prune(3))->toBe(2)
        ->and(array_map('basename', app(DatabaseBackup::class)->list()))->toBe([
            'meja-20261005-043000.sql.gz',
            'meja-20261004-043000.sql.gz',
            'meja-20261003-043000.sql.gz',
        ])
        ->and(File::exists($this->folder.'/catatan.txt'))->toBeTrue();
});

it('is scheduled every morning', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'meja:backup'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 4 * * *');
});
