<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Actions\DeleteEnvVar;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class EnvVarControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_page_never_contains_a_secret_value_or_a_datastore_value(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'APP_ENV', 'value' => 'production']);
        EnvVar::factory()->for($environment)->create(['key' => 'STRIPE_SECRET', 'value' => 'sk_live_TOP_SECRET_VALUE', 'is_secret' => true]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'datastore-password-123', 'source' => EnvVarSource::DATASTORE, 'is_secret' => true]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'value' => 'mysql.internal', 'source' => EnvVarSource::DATASTORE]);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/settings")->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('sk_live_TOP_SECRET_VALUE', $raw);
        $this->assertStringNotContainsString('datastore-password-123', $raw);
        $this->assertStringNotContainsString('mysql.internal', $raw);
        $this->assertStringContainsString('production', $raw);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('environments/settings')
            ->has('envVars', 4)
            ->where('envVars.0', ['id' => $environment->envVars()->where('key', 'APP_ENV')->value('id'), 'key' => 'APP_ENV', 'source' => 'user', 'is_secret' => false, 'available_at' => 'runtime', 'resource' => null, 'value' => 'production'])
            ->where('envVars.3.key', 'STRIPE_SECRET')
            ->where('envVars.3.value', null)
            ->where('envVars.3.is_secret', true)
            ->where('envVars.1.source', 'datastore')
            ->where('envVars.1.value', null));
    }

    public function test_a_resource_value_names_the_resource_that_owns_it(): void
    {
        $environment = $this->makeEnvironment();
        $cluster = $this->makeCluster();
        $postgres = DatastoreAttachment::factory()->create([
            'datastore_id' => Datastore::factory()->create(['cluster_id' => $cluster->id, 'kind' => DatastoreKind::POSTGRES])->id,
            'environment_id' => $environment->id,
            'name' => 'main',
        ]);
        $valkey = DatastoreAttachment::factory()->create([
            'datastore_id' => Datastore::factory()->create(['cluster_id' => $cluster->id, 'kind' => DatastoreKind::VALKEY])->id,
            'environment_id' => $environment->id,
            'name' => null,
        ]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $postgres->id]);
        EnvVar::factory()->for($environment)->create(['key' => 'REDIS_HOST', 'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $valkey->id]);
        EnvVar::factory()->for($environment)->create(['key' => 'APP_ENV', 'value' => 'production']);

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/settings")->assertInertia(fn (Assert $page) => $page
            ->where('envVars.0.resource', null)
            ->where('envVars.1.resource', 'Postgres main')
            ->where('envVars.2.resource', 'Valkey'));
    }

    public function test_the_inertia_json_response_never_contains_the_secret_either(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'STRIPE_SECRET', 'value' => 'sk_live_TOP_SECRET_VALUE', 'is_secret' => true]);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->get("/environments/{$environment->id}/settings", ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->assertOk();

        $this->assertStringNotContainsString('sk_live_TOP_SECRET_VALUE', $response->getContent());
        $this->assertStringNotContainsString('kubeconfig', $response->getContent());
    }

    public function test_the_old_env_page_opens_the_values_in_the_settings(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}/env")
            ->assertRedirect("/environments/{$environment->id}/settings#environment-values");
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
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($given, User $by, string $key, ?string $value, bool $isSecret, EnvVarAvailability $at) => $given->is($environment) && $by->is($developer)
                && [$key, $value, $isSecret, $at] === ['MAIL_MAILER', 'log', false, EnvVarAvailability::BOTH]);

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
        $this->assertSame(['sk_live_1', EnvVarAvailability::BUILD], [$envVar->value, $envVar->available_at]);
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
        EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::DATASTORE]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => '1BAD', 'value' => 'x', 'available_at' => 'runtime'])->assertSessionHasErrors('key');
        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => 'OK', 'value' => 'x', 'available_at' => 'never'])->assertSessionHasErrors('available_at');
        $this->actingAs($admin)->post("/environments/{$environment->id}/env", ['key' => 'DB_HOST', 'value' => 'x', 'available_at' => 'runtime'])->assertSessionHasErrors('key');
    }

    public function test_a_refused_value_form_never_flashes_the_value(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->admin()->create())->from("/environments/{$environment->id}/settings")
            ->post("/environments/{$environment->id}/env", ['key' => '1BAD', 'value' => 'sk_live_TOP_SECRET', 'is_secret' => true, 'available_at' => 'runtime'])
            ->assertRedirect("/environments/{$environment->id}/settings")
            ->assertSessionHasErrors('key');

        $this->assertSame([null, '1BAD'], [session()->getOldInput('value'), session()->getOldInput('key')]);
        $this->assertStringNotContainsString('sk_live_TOP_SECRET', json_encode(session()->all()));
    }

    public function test_it_deletes_a_user_value_through_the_action(): void
    {
        $environment = $this->makeEnvironment();
        $envVar = EnvVar::factory()->for($environment)->create(['key' => 'OLD']);
        $admin = User::factory()->admin()->create();

        $this->mock(DeleteEnvVar::class)->shouldReceive('execute')->once()->withArgs(fn (EnvVar $given, User $by) => $given->is($envVar) && $by->is($admin));

        $this->actingAs($admin)->delete("/env-vars/{$envVar->id}")->assertRedirect();
    }

    public function test_the_page_sends_the_plain_values_as_dotenv_text(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'B_KEY', 'value' => 'two words']);
        EnvVar::factory()->for($environment)->create(['key' => 'A_KEY', 'value' => 'one']);
        EnvVar::factory()->for($environment)->create(['key' => 'TOKEN', 'value' => 'sk_live_TOP_SECRET_VALUE', 'is_secret' => true]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'value' => 'mysql.internal', 'source' => EnvVarSource::DATASTORE]);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/settings");

        $response->assertInertia(fn (Assert $page) => $page->where('envText', "A_KEY=one\nB_KEY='two words'\n"));
        $this->assertStringNotContainsString('sk_live_TOP_SECRET_VALUE', $response->getContent());
        $this->assertStringNotContainsString('mysql.internal', $response->getContent());
    }

    public function test_the_text_route_saves_the_whole_text_once(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        EnvVar::factory()->for($environment)->create(['key' => 'OLD', 'value' => '1']);

        $this->actingAs(User::factory()->create())->put("/environments/{$environment->id}/env", ['text' => "NEW=2\n"])->assertRedirect();

        $this->assertSame(['NEW' => '2'], $environment->envVars()->pluck('value', 'key')->all());
    }

    public function test_the_text_route_shows_the_bad_lines(): void
    {
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'OLD', 'value' => '1']);

        $this->actingAs(User::factory()->admin()->create())->put("/environments/{$environment->id}/env", ['text' => "A=1\nB"])
            ->assertSessionHasErrors(['text' => 'Line 2 has no = sign.']);

        $this->assertSame(['OLD'], $environment->envVars()->pluck('key')->all());
    }

    public function test_a_developer_cannot_save_the_text_of_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment(true);
        EnvVar::factory()->for($environment)->create(['key' => 'OLD', 'value' => '1']);

        $this->actingAs(User::factory()->create())->put("/environments/{$environment->id}/env", ['text' => ''])->assertForbidden();

        $this->assertSame(['OLD'], $environment->envVars()->pluck('key')->all());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_datastore_value_cannot_be_deleted(): void
    {
        $environment = $this->makeEnvironment();
        $envVar = EnvVar::factory()->for($environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::DATASTORE]);

        $this->actingAs(User::factory()->admin()->create())->delete("/env-vars/{$envVar->id}")->assertSessionHasErrors('key');

        $this->assertNotNull($envVar->fresh());
    }
}
