<?php

namespace Tests\Feature\Deploy;

use App\Deploy\EnvironmentVariables;
use App\Enums\ApplicationType;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvVar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_values_beat_datastore_values_and_both_beat_the_system_defaults(): void
    {
        $environment = Environment::factory()->create();
        Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com']);
        Domain::factory()->for($environment)->create(['hostname' => 'www.shop.example.com']);
        $this->envVar($environment, 'DB_HOST', 'mysql.oc-datastores.svc.cluster.local', EnvVarSource::Datastore);
        $this->envVar($environment, 'DB_PASSWORD', 'from-datastore', EnvVarSource::Datastore);
        $this->envVar($environment, 'DB_PASSWORD', 'from-user', EnvVarSource::User);
        $this->envVar($environment, 'APP_DEBUG', 'true', EnvVarSource::User);
        $this->envVar($environment, 'LOG_CHANNEL', 'datastore-says', EnvVarSource::Datastore);
        $this->envVar($environment, 'COMPOSER_AUTH', '{"github-oauth":{}}', EnvVarSource::User, EnvVarAvailability::Build);
        $this->envVar($environment, 'NPM_TOKEN', 'npm-123', EnvVarSource::User, EnvVarAvailability::Both);

        $this->assertSame([
            'APP_DEBUG' => 'true',
            'APP_ENV' => 'production',
            'APP_URL' => 'https://shop.example.com',
            'DB_HOST' => 'mysql.oc-datastores.svc.cluster.local',
            'DB_PASSWORD' => 'from-user',
            'LOG_CHANNEL' => 'datastore-says',
            'NPM_TOKEN' => 'npm-123',
        ], EnvironmentVariables::runtime($environment));

        $this->assertSame([
            'COMPOSER_AUTH' => '{"github-oauth":{}}',
            'NPM_TOKEN' => 'npm-123',
        ], EnvironmentVariables::build($environment));
    }

    public function test_a_laravel_app_without_a_domain_gets_no_app_url(): void
    {
        $environment = Environment::factory()->create();

        $this->assertSame(
            ['APP_DEBUG' => 'false', 'APP_ENV' => 'production', 'LOG_CHANNEL' => 'stderr'],
            EnvironmentVariables::runtime($environment),
        );
    }

    public function test_a_dockerfile_app_gets_no_system_defaults(): void
    {
        $application = Application::factory()->create(['type' => ApplicationType::Dockerfile]);
        $environment = Environment::factory()->for($application)->create();
        Domain::factory()->for($environment)->create(['hostname' => 'api.example.com']);
        $this->envVar($environment, 'PORT', '3000', EnvVarSource::User);

        $this->assertSame(['PORT' => '3000'], EnvironmentVariables::runtime($environment));
    }

    public function test_values_of_other_environments_stay_out(): void
    {
        $environment = Environment::factory()->create();
        $other = Environment::factory()->for($environment->application)->create();
        $this->envVar($other, 'SECRET_OF_OTHER', 'nope', EnvVarSource::User);

        $this->assertArrayNotHasKey('SECRET_OF_OTHER', EnvironmentVariables::runtime($environment));
        $this->assertSame(['SECRET_OF_OTHER' => 'nope'], array_intersect_key(EnvironmentVariables::runtime($other), ['SECRET_OF_OTHER' => true]));
    }

    private function envVar(Environment $environment, string $key, string $value, EnvVarSource $source, EnvVarAvailability $availableAt = EnvVarAvailability::Runtime): void
    {
        EnvVar::factory()->for($environment)->create([
            'key' => $key,
            'value' => $value,
            'source' => $source,
            'available_at' => $availableAt,
        ]);
    }
}
