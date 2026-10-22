<?php

namespace Tests\Feature\Http;

use App\Actions\DeleteEnvVar;
use App\Actions\SetEnvVar;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditEvent;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnvVarControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_page_never_contains_a_secret_value_or_a_datastore_value(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'APP_ENV', 'value' => 'production']);
        EnvVar::factory()->for($environment)->create(['key' => 'STRIPE_SECRET', 'value' => 'sk_live_TOP_SECRET_VALUE', 'is_secret' => true]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'datastore-password-123', 'source' => EnvVarSource::Datastore, 'is_secret' => true]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'value' => 'mysql.internal', 'source' => EnvVarSource::Datastore]);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/env")->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('sk_live_TOP_SECRET_VALUE', $raw);
        $this->assertStringNotContainsString('datastore-password-123', $raw);
        $this->assertStringNotContainsString('mysql.internal', $raw);
        $this->assertStringContainsString('production', $raw);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('environments/env')
            ->has('envVars', 4)
            ->where('envVars.0', ['id' => $environment->envVars()->where('key', 'APP_ENV')->value('id'), 'key' => 'APP_ENV', 'source' => 'user', 'is_secret' => false, 'available_at' => 'runtime', 'value' => 'production'])
            ->where('envVars.3.key', 'STRIPE_SECRET')
            ->where('envVars.3.value', null)
            ->where('envVars.3.is_secret', true)
            ->where('envVars.1.source', 'datastore')
            ->where('envVars.1.value', null));
    }

    public function test_the_inertia_json_response_never_contains_the_secret_either(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'STRIPE_SECRET', 'value' => 'sk_live_TOP_SECRET_VALUE', 'is_secret' => true]);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->get("/environments/{$environment->id}/env", ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->assertOk();

        $this->assertStringNotContainsString('sk_live_TOP_SECRET_VALUE', $response->getContent());
        $this->assertStringNotContainsString('kubeconfig', $response->getContent());
    }

    private function inertiaVersion(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }

    public function test_a_developer_cannot_change_values_of_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment(true);
        $secret = EnvVar::factory()->for($environment)->create(['key' => 'A', 'value' => 'x']);

        $this->actingAs(User::factory()->create())->post("/environments/{$environment->id}/env", ['key' => 'B', 'value' => 'y', 'available_at' => 'runtime'])->assertForbidden();
        $this->actingAs(User::factory()->create())->delete("/env-vars/{$secret->id}")->assertForbidden();

        $this->assertSame(['A'], $environment->envVars()->pluck('key')->all());
    }

    public function test_a_developer_sets_and_deletes_values_of_an_unprotected_environment(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $developer = User::factory()->create();

        $this->mock(SetEnvVar::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn ($given, User $by, string $key, ?string $value, bool $isSecret, EnvVarAvailability $at) => $given->is($environment) && $by->is($developer)
                && [$key, $value, $isSecret, $at] === ['MAIL_MAILER', 'log', false, EnvVarAvailability::Both]);

        $this->actingAs($developer)->post("/environments/{$environment->id}/env", ['key' => 'MAIL_MAILER', 'value' => 'log', 'is_secret' => false, 'available_at' => 'both'])->assertRedirect();
    }

    public function test_it_stores_a_secret_and_audits_it_without_the_value(): void
    {
        $environment = $this->makeEnvironment();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_1', 'is_secret' => true, 'available_at' => 'runtime'])->assertRedirect();

        $envVar = EnvVar::sole();
        $this->assertSame(['STRIPE_SECRET', 'sk_live_1', true], [$envVar->key, $envVar->value, $envVar->is_secret]);
        $this->assertStringNotContainsString('sk_live_1', json_encode(AuditEvent::sole()->context));
    }

    public function test_an_empty_value_keeps_an_existing_secret(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'STRIPE_SECRET', 'value' => 'sk_live_1', 'is_secret' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->post("/environments/{$environment->id}/env", ['key' => 'STRIPE_SECRET', 'value' => '', 'is_secret' => true, 'available_at' => 'build'])
            ->assertRedirect();

        $envVar = EnvVar::sole();
        $this->assertSame(['sk_live_1', EnvVarAvailability::Build], [$envVar->value, $envVar->available_at]);
    }

    public function test_a_kept_secret_cannot_become_plain(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'STRIPE_SECRET', 'value' => 'sk_live_1', 'is_secret' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->post("/environments/{$environment->id}/env", ['key' => 'STRIPE_SECRET', 'value' => '', 'is_secret' => false, 'available_at' => 'runtime'])
            ->assertSessionHasErrors('value');

        $this->assertTrue(EnvVar::sole()->is_secret);
    }

    public function test_the_value_form_is_validated(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::Datastore]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => '1BAD', 'value' => 'x', 'available_at' => 'runtime'])->assertSessionHasErrors('key');
        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => 'OK', 'value' => 'x', 'available_at' => 'never'])->assertSessionHasErrors('available_at');
        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => 'DB_HOST', 'value' => 'x', 'available_at' => 'runtime'])->assertSessionHasErrors('key');
    }

    public function test_it_deletes_a_user_value_through_the_action(): void
    {
        $environment = $this->makeEnvironment();
        $envVar = EnvVar::factory()->for($environment)->create(['key' => 'OLD']);
        $admin = User::factory()->admin()->create();

        $this->mock(DeleteEnvVar::class)->shouldReceive('handle')->once()->withArgs(fn (EnvVar $given, User $by) => $given->is($envVar) && $by->is($admin));

        $this->actingAs($admin)->delete("/env-vars/{$envVar->id}")->assertRedirect();
    }

    public function test_a_datastore_value_cannot_be_deleted(): void
    {
        $environment = $this->makeEnvironment();
        $envVar = EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::Datastore]);

        $this->actingAs(User::factory()->admin()->create())->delete("/env-vars/{$envVar->id}")->assertSessionHasErrors('key');

        $this->assertNotNull($envVar->fresh());
    }
}
