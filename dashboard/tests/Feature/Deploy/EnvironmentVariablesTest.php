<?php

namespace Tests\Feature\Deploy;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Enums\EnvProfileKind;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\EnvironmentVariables;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{ApplicationType, int, array<string, string>}> */
    public static function presets(): array
    {
        return [
            'laravel' => [ApplicationType::Laravel, 8080, ['APP_DEBUG' => 'false', 'APP_ENV' => 'production', 'APP_URL' => 'https://api.example.com', 'LOG_CHANNEL' => 'stderr']],
            'node' => [ApplicationType::Node, 3000, ['APP_URL' => 'https://api.example.com', 'NODE_ENV' => 'production', 'PORT' => '3000']],
            'ruby' => [ApplicationType::Ruby, 3000, ['APP_URL' => 'https://api.example.com', 'PORT' => '3000', 'RACK_ENV' => 'production', 'RAILS_ENV' => 'production', 'RAILS_LOG_TO_STDOUT' => '1', 'RAILS_SERVE_STATIC_FILES' => '1']],
            'python' => [ApplicationType::Python, 8000, ['ALLOWED_HOSTS' => 'api.example.com,www.example.com', 'APP_URL' => 'https://api.example.com', 'DEBUG' => 'false', 'PORT' => '8000', 'PYTHONUNBUFFERED' => '1']],
            'static' => [ApplicationType::Static, 8080, ['APP_URL' => 'https://api.example.com', 'PORT' => '8080']],
            'dockerfile' => [ApplicationType::Dockerfile, 3000, ['APP_URL' => 'https://api.example.com', 'PORT' => '3000']],
        ];
    }

    /** @param  array<string, string>  $expected */
    #[DataProvider('presets')]
    public function test_each_preset_has_its_own_system_values(ApplicationType $type, int $port, array $expected): void
    {
        $application = Application::factory()->create(['type' => $type, 'port' => $port]);
        $environment = Environment::factory()->for($application)->create();
        Domain::factory()->for($environment)->create(['hostname' => 'api.example.com']);
        Domain::factory()->for($environment)->create(['hostname' => 'www.example.com']);

        $this->assertSame($expected, EnvironmentVariables::runtime($environment));
    }

    public function test_a_python_app_without_a_domain_gets_no_url_and_no_allowed_hosts(): void
    {
        $application = Application::factory()->create(['type' => ApplicationType::Python, 'port' => 8000]);
        $environment = Environment::factory()->for($application)->create();

        $this->assertSame(['DEBUG' => 'false', 'PORT' => '8000', 'PYTHONUNBUFFERED' => '1'], EnvironmentVariables::runtime($environment));
    }

    public function test_a_user_port_beats_the_port_of_the_application(): void
    {
        $application = Application::factory()->create(['type' => ApplicationType::Dockerfile, 'port' => 3000]);
        $environment = Environment::factory()->for($application)->create();
        $this->envVar($environment, 'PORT', '4000', EnvVarSource::User);

        $this->assertSame(['PORT' => '4000'], EnvironmentVariables::runtime($environment));
    }

    public function test_values_of_other_environments_stay_out(): void
    {
        $environment = Environment::factory()->create();
        $other = Environment::factory()->for($environment->application)->create();
        $this->envVar($other, 'SECRET_OF_OTHER', 'nope', EnvVarSource::User);

        $this->assertArrayNotHasKey('SECRET_OF_OTHER', EnvironmentVariables::runtime($environment));
        $this->assertSame(['SECRET_OF_OTHER' => 'nope'], array_intersect_key(EnvironmentVariables::runtime($other), ['SECRET_OF_OTHER' => true]));
    }

    public function test_the_env_profile_of_the_application_names_the_database_values(): void
    {
        $laravel = $this->environmentWithMysql(Application::factory()->create(['env_profile' => EnvProfileKind::Laravel]));
        $generic = $this->environmentWithMysql(Application::factory()->create(['env_profile' => EnvProfileKind::Generic]));

        $this->assertSame([
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'env_1',
            'DB_HOST' => 'mysql.oc-datastores.svc.cluster.local',
            'DB_PASSWORD' => 'p/ss',
            'DB_PORT' => '3306',
            'DB_USERNAME' => 'env_1',
        ], $this->datastoreValues($laravel));
        $this->assertSame(['DATABASE_URL' => 'mysql://env_1:p%2Fss@mysql.oc-datastores.svc.cluster.local:3306/env_1'], $this->datastoreValues($generic));
    }

    public function test_an_application_from_before_the_env_profile_column_keeps_the_laravel_names(): void
    {
        $cluster = Cluster::factory()->create();
        DB::table('applications')->insert(['name' => 'old', 'repository' => 'acme/old', 'cluster_id' => $cluster->id, 'type' => 'dockerfile']);

        $application = Application::sole();

        $this->assertSame(EnvProfileKind::Laravel, $application->env_profile);
        $this->assertSame(['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'], array_keys($this->datastoreValues($this->environmentWithMysql($application))));
    }

    private function environmentWithMysql(Application $application): Environment
    {
        $environment = Environment::factory()->for($application)->create();
        $datastore = Datastore::factory()->for($application->cluster)->create(['kind' => DatastoreKind::Mysql]);
        $attachment = DatastoreAttachment::factory()->for($datastore)->for($environment)->create([
            'name' => 'main',
            'is_default' => true,
            'host' => 'mysql.oc-datastores.svc.cluster.local',
            'port' => 3306,
            'database_name' => 'env_1',
            'username' => 'env_1',
            'password' => 'p/ss',
        ]);
        AttachmentEnv::write($attachment);

        return $environment;
    }

    /** @return array<string, string> */
    private function datastoreValues(Environment $environment): array
    {
        return $environment->envVars()->where('source', EnvVarSource::Datastore)->orderBy('key')->pluck('value', 'key')->all();
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
