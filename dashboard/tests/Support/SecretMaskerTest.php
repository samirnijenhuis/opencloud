<?php

namespace Tests\Support;

use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Support\SecretMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecretMaskerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_masks_the_secret_values_of_the_environment_only(): void
    {
        $environment = Environment::factory()->create();
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);
        EnvVar::factory()->for($environment)->create(['key' => 'APP_NAME', 'value' => 'Shop', 'is_secret' => false]);
        EnvVar::factory()->for(Environment::factory())->create(['key' => 'OTHER', 'value' => 'other-secret', 'is_secret' => true]);

        $masker = SecretMasker::forEnvironment($environment);

        $this->assertSame(
            'connect Shop as root:******** (other-secret stays)',
            $masker->mask('connect Shop as root:hunter22 (other-secret stays)'),
        );
    }

    public function test_it_masks_a_database_password_alone_and_url_encoded_when_only_a_url_value_holds_it(): void
    {
        $environment = Environment::factory()->create();
        DatastoreAttachment::factory()->for($environment)->create(['name' => 'reports', 'password' => 'p@ss/word-xyz']);
        EnvVar::factory()->for($environment)->create([
            'key' => 'REPORTS_DB_URL',
            'value' => 'postgres://env_1_0b7ec688:p%40ss%2Fword-xyz@postgres-1.oc-datastores.svc.cluster.local:5432/env_1_reports',
            'is_secret' => true,
            'source' => EnvVarSource::Datastore,
        ]);

        $masker = SecretMasker::forEnvironment($environment);

        $this->assertSame(
            'url ******** password ******** encoded postgres://env_1_0b7ec688:********@postgres-1',
            $masker->mask('url postgres://env_1_0b7ec688:p%40ss%2Fword-xyz@postgres-1.oc-datastores.svc.cluster.local:5432/env_1_reports password p@ss/word-xyz encoded postgres://env_1_0b7ec688:p%40ss%2Fword-xyz@postgres-1'),
        );
    }

    public function test_it_skips_values_shorter_than_four_characters(): void
    {
        $masker = new SecretMasker(['abc', 'abcd', '']);

        $this->assertSame('abc ******** abc', $masker->mask('abc abcd abc'));
    }

    public function test_a_secret_that_contains_another_secret_is_masked_whole(): void
    {
        $masker = new SecretMasker(['pass', 'password-long']);

        $this->assertSame('******** and ********', $masker->mask('password-long and pass'));
    }

    public function test_each_line_of_a_multi_line_secret_is_masked(): void
    {
        $masker = new SecretMasker(["-----BEGIN KEY-----\nMIIEvQIBADANBg\n-----END KEY-----"]);

        $this->assertSame('key line ********', $masker->mask('key line MIIEvQIBADANBg'));
    }

    public function test_without_secrets_the_text_is_unchanged(): void
    {
        $this->assertSame('plain line', (new SecretMasker([]))->mask('plain line'));
    }
}
