<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PDO;
use Tests\TestCase;

/** VACUUM cannot run inside the transaction of RefreshDatabase, so this class migrates for each test. */
class CopyDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/opencloud-copy-'.bin2hex(random_bytes(4)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_the_copy_opens_as_sqlite_and_has_the_same_rows(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);
        Cluster::factory()->create(['name' => 'do-ams3']);

        $this->artisan('opencloud:copy-database', ['path' => $this->path])
            ->expectsOutput("Copied the database to {$this->path}.")
            ->assertSuccessful();

        $copy = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(['owner@example.com'], $copy->query('select email from users')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['do-ams3'], $copy->query('select name from clusters')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_it_refuses_to_overwrite_a_file(): void
    {
        file_put_contents($this->path, 'keep me');

        $this->artisan('opencloud:copy-database', ['path' => $this->path])
            ->expectsOutput("The file {$this->path} exists. Give a new file name.")
            ->assertFailed();

        $this->assertSame('keep me', file_get_contents($this->path));
    }
}
