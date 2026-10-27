<?php

namespace Tests\Feature\Tofu;

use App\Domains\Clusters\Models\Cluster;
use App\Support\Tofu\Runner;
use App\Support\Tofu\Stack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

class RunnerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_FAKE_TOKEN_0123456789';

    private string $data;

    private FakeTofu $tofu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = sys_get_temp_dir().'/opencloud-runner-test-'.getmypid();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'opencloud.data_path' => $this->data,
            'opencloud.tofu_modules' => base_path('tests/Fixtures/tofu'),
            'opencloud.tofu_binary' => '/usr/local/bin/tofu',
        ]);
        $this->tofu = FakeTofu::install();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->data);

        parent::tearDown();
    }

    public function test_apply_runs_init_apply_and_output_in_the_work_folder_and_returns_the_outputs(): void
    {
        $this->tofu->outputs = ['id' => 'abc', 'kubeconfig' => "apiVersion: v1\n"];

        $outputs = (new Runner)->apply($this->stack(), Cluster::factory()->create());

        $this->assertSame(['id' => 'abc', 'kubeconfig' => "apiVersion: v1\n"], $outputs);
        $this->assertSame([
            ['init', '-input=false', '-no-color'],
            ['apply', '-auto-approve', '-input=false', '-no-color', '-var-file=opencloud.tfvars.json'],
            ['output', '-json', '-no-color'],
        ], $this->tofu->commands());
        $this->assertSame(["{$this->data}/tofu/cluster-7"], array_values(array_unique(array_column($this->tofu->ran, 'path'))));
        $this->assertFileExists("{$this->data}/tofu/cluster-7/main.tf");
    }

    public function test_the_token_is_only_in_the_env_and_the_variables_are_in_a_file_of_mode_600(): void
    {
        (new Runner)->apply($this->stack(), Cluster::factory()->create());

        foreach ($this->tofu->ran as $call) {
            $this->assertSame(self::TOKEN, $call['env']['DIGITALOCEAN_TOKEN']);
            $this->assertStringNotContainsString(self::TOKEN, implode(' ', $call['args']));
            $this->assertSame('600', $call['var_file_mode']);
            $this->assertSame(['name' => 'ams3-prod', 'min_nodes' => 1, 'create_registry' => true], $call['variables']);
        }

        $this->assertStringNotContainsString(self::TOKEN, file_get_contents("{$this->data}/tofu/cluster-7/opencloud.tfvars.json"));
        $this->assertSame('700', substr(sprintf('%o', fileperms("{$this->data}/tofu/cluster-7")), -3));
    }

    public function test_state_encryption_is_enforced_with_a_passphrase_from_the_app_key(): void
    {
        (new Runner)->apply($this->stack(), Cluster::factory()->create());

        $passphrase = hash_hmac('sha256', 'opencloud-tofu-state', 'base64:'.base64_encode(str_repeat('k', 32)));
        $expected = <<<HCL
        key_provider "pbkdf2" "opencloud" {
          passphrase = "{$passphrase}"
        }
        method "aes_gcm" "opencloud" {
          keys = key_provider.pbkdf2.opencloud
        }
        state {
          method   = method.aes_gcm.opencloud
          enforced = true
        }
        plan {
          method   = method.aes_gcm.opencloud
          enforced = true
        }
        HCL;

        foreach ($this->tofu->ran as $call) {
            $this->assertSame($expected, $call['env']['TF_ENCRYPTION']);
        }

        foreach (File::allFiles("{$this->data}/tofu/cluster-7", hidden: true) as $file) {
            $this->assertStringNotContainsString($passphrase, $file->getContents(), $file->getFilename());
        }
    }

    public function test_the_log_gets_every_line_with_the_token_masked_and_never_the_outputs(): void
    {
        $cluster = Cluster::factory()->create();
        $this->tofu->lines = [
            'init' => ['Initializing provider plugins...'],
            'apply' => ['digitalocean_kubernetes_cluster.this: Creating...', 'token '.self::TOKEN.' in a line'],
        ];
        $this->tofu->outputs = ['kubeconfig' => 'KUBECONFIG_SECRET_VALUE'];

        (new Runner)->apply($this->stack(), $cluster);

        $lines = array_map(fn (string $line) => substr($line, 21), explode("\n", trim($cluster->fresh()->tofu_log)));
        $this->assertSame([
            '$ tofu init -input=false -no-color',
            'Initializing provider plugins...',
            '$ tofu apply -auto-approve -input=false -no-color -var-file=opencloud.tfvars.json',
            'digitalocean_kubernetes_cluster.this: Creating...',
            'token ******** in a line',
        ], $lines);
    }

    public function test_a_failed_apply_throws_the_error_block_with_secrets_masked(): void
    {
        $this->tofu->failOn = 'apply';
        $this->tofu->error = "\n╷\n│ Error: POST https://api.digitalocean.com/v2/kubernetes/clusters: 401 Unable to authenticate you\n│ \n│ token ".self::TOKEN."\n╵\n";

        try {
            (new Runner)->apply($this->stack(), Cluster::factory()->create());
            $this->fail('The apply did not throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('tofu apply failed. Error: POST https://api.digitalocean.com/v2/kubernetes/clusters: 401 Unable to authenticate you token ********', $e->getMessage());
        }

        $this->assertSame(['init', 'apply'], array_column($this->tofu->commands(), 0));
    }

    public function test_a_failed_init_runs_again_and_three_failures_stop_the_run(): void
    {
        Sleep::fake();
        $this->tofu->failOn = 'init';
        $this->tofu->failTimes = 1;
        $this->tofu->error = 'Error: Failed to resolve provider packages: context deadline exceeded';

        (new Runner)->apply($this->stack(), Cluster::factory()->create());

        $this->assertSame(['init', 'init', 'apply', 'output'], array_column($this->tofu->commands(), 0));

        $this->tofu->ran = [];
        $this->tofu->failTimes = 3;

        try {
            (new Runner)->apply($this->stack(), Cluster::factory()->create());
            $this->fail('The apply did not throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('tofu init failed. Error: Failed to resolve provider packages: context deadline exceeded', $e->getMessage());
        }

        $this->assertSame(['init', 'init', 'init'], array_column($this->tofu->commands(), 0));
    }

    public function test_destroy_runs_init_and_destroy_then_removes_the_work_folder(): void
    {
        (new Runner)->destroy($this->stack(), Cluster::factory()->create());

        $this->assertSame([
            ['init', '-input=false', '-no-color'],
            ['destroy', '-auto-approve', '-input=false', '-no-color', '-suppress-forget-errors', '-var-file=opencloud.tfvars.json'],
        ], $this->tofu->commands());
        $this->assertDirectoryDoesNotExist("{$this->data}/tofu/cluster-7");
    }

    public function test_a_failed_destroy_keeps_the_work_folder_and_its_state(): void
    {
        $this->tofu->failOn = 'destroy';

        $this->expectException(RuntimeException::class);

        try {
            (new Runner)->destroy($this->stack(), Cluster::factory()->create());
        } finally {
            $this->assertDirectoryExists("{$this->data}/tofu/cluster-7");
        }
    }

    public function test_outputs_are_parsed_to_name_and_value(): void
    {
        $json = '{"cluster_id":{"sensitive":false,"type":"string","value":"c-1"},"port":{"sensitive":false,"type":"number","value":25060}}';

        $this->assertSame(['cluster_id' => 'c-1', 'port' => 25060], Runner::parseOutputs($json));
    }

    public function test_a_stack_name_must_be_kind_and_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Stack('local', '../../etc', [], []);
    }

    private function stack(): Stack
    {
        return new Stack('local', 'cluster-7', ['name' => 'ams3-prod', 'min_nodes' => 1, 'create_registry' => true], ['DIGITALOCEAN_TOKEN' => self::TOKEN]);
    }
}
