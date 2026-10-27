<?php

namespace Tests\Database;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class SqliteTransactionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'opencloud-sqlite-');
        config(['database.connections.shared' => [...config('database.connections.sqlite'), 'database' => $this->file]]);
        DB::connection('shared')->statement('create table jobs (id integer primary key, reserved_at integer)');
        DB::connection('shared')->table('jobs')->insert(['reserved_at' => null]);
    }

    protected function tearDown(): void
    {
        DB::purge('shared');
        foreach ([$this->file, "{$this->file}-wal", "{$this->file}-shm"] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_transaction_that_reads_then_writes_never_meets_a_write_from_another_container(): void
    {
        $other = new PDO("sqlite:{$this->file}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('pragma busy_timeout = 0');
        $otherWrote = true;

        DB::connection('shared')->transaction(function () use ($other, &$otherWrote) {
            $id = DB::connection('shared')->table('jobs')->whereNull('reserved_at')->value('id');

            try {
                $other->exec('insert into jobs (reserved_at) values (null)');
            } catch (PDOException) {
                $otherWrote = false;
            }

            DB::connection('shared')->table('jobs')->where('id', $id)->update(['reserved_at' => 1792838758]);
        });

        $this->assertFalse($otherWrote, 'The other container waits until the transaction ends.');
        $this->assertSame([[1, 1792838758]], DB::connection('shared')->table('jobs')->get()->map(fn ($job) => [$job->id, $job->reserved_at])->all());
    }
}
