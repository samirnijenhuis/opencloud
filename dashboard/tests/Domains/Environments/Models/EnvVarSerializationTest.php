<?php

namespace Tests\Domains\Environments\Models;

use App\Domains\Environments\Models\EnvVar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvVarSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_secret_never_serializes_its_value(): void
    {
        $envVar = EnvVar::factory()->create([
            'key' => 'STRIPE_SECRET',
            'value' => 'sk_live_do-not-show',
            'is_secret' => true,
        ])->fresh();

        $array = $envVar->toArray();

        $this->assertArrayHasKey('value', $array);
        $this->assertNull($array['value']);
        $this->assertTrue($array['has_value']);
        $this->assertSame('STRIPE_SECRET', $array['key']);
        $this->assertStringNotContainsString('sk_live_do-not-show', $envVar->toJson());
        $this->assertStringNotContainsString('sk_live_do-not-show', json_encode(EnvVar::all()));
        $this->assertSame('sk_live_do-not-show', $envVar->value);
    }

    public function test_a_plain_value_serializes_its_value(): void
    {
        $envVar = EnvVar::factory()->create(['key' => 'APP_DEBUG', 'value' => 'false'])->fresh();

        $this->assertSame('false', $envVar->toArray()['value']);
        $this->assertTrue($envVar->toArray()['has_value']);
    }

    public function test_an_empty_secret_reports_no_value(): void
    {
        $envVar = EnvVar::factory()->create(['key' => 'API_TOKEN', 'value' => null, 'is_secret' => true])->fresh();

        $this->assertNull($envVar->toArray()['value']);
        $this->assertFalse($envVar->toArray()['has_value']);
    }
}
