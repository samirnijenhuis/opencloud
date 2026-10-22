<?php

namespace Tests\Feature\Support;

use App\Models\Environment;
use App\Models\EnvVar;
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
