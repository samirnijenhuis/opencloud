<?php

namespace Tests\Support\Tofu;

use App\Domains\Clusters\Models\Cluster;
use App\Support\Tofu\Runner;
use App\Support\Tofu\Stack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Runs the real tofu binary against a module with no cloud provider. Set OPENCLOUD_TEST_TOFU_BINARY to run it.
 */
class StateEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private string $data;

    protected function setUp(): void
    {
        parent::setUp();

        $binary = (string) getenv('OPENCLOUD_TEST_TOFU_BINARY');

        if ($binary === '' || ! is_executable($binary)) {
            $this->markTestSkipped('Set OPENCLOUD_TEST_TOFU_BINARY to a tofu binary to run this test.');
        }

        $this->data = sys_get_temp_dir().'/opencloud-tofu-encryption-'.getmypid();
        config([
            'opencloud.tofu_binary' => $binary,
            'opencloud.tofu_modules' => base_path('tests/Fixtures/tofu'),
            'opencloud.data_path' => $this->data,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->data)) {
            File::deleteDirectory($this->data);
        }

        parent::tearDown();
    }

    public function test_the_state_on_disk_is_encrypted_and_a_second_apply_reads_it_back(): void
    {
        $cluster = Cluster::factory()->create();
        $stack = new Stack('local', 'proof-1', ['message' => 'PLAIN_STATE_MARKER'], ['DIGITALOCEAN_TOKEN' => 'dop_v1_FAKE_TOKEN_NOT_REAL']);

        $first = (new Runner)->apply($stack, $cluster);
        $state = file_get_contents(Runner::workPath($stack).'/terraform.tfstate');
        $second = (new Runner)->apply($stack, $cluster);

        $this->assertSame('PLAIN_STATE_MARKER', $first['message']);
        $this->assertSame($first, $second, 'The second apply read the state back and kept the same resource.');
        $this->assertStringNotContainsString('PLAIN_STATE_MARKER', $state);
        $this->assertStringNotContainsString('terraform_data', $state);
        $this->assertSame('v0', json_decode($state, true)['encryption_version']);
        $this->assertStringContainsString('No changes.', $cluster->fresh()->tofu_log);

        $plain = Process::path(Runner::workPath($stack))->env(['TF_ENCRYPTION' => false])->run([config('opencloud.tofu_binary'), 'show', '-no-color']);
        $this->assertTrue($plain->failed(), 'tofu without the key cannot read the state.');
        $this->assertStringContainsString('encrypted', $plain->errorOutput());
    }
}
