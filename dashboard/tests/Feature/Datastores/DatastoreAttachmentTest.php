<?php

namespace Tests\Feature\Datastores;

use App\Actions\AttachDatastore;
use App\Actions\DetachDatastore;
use App\Actions\SetEnvVar;
use App\Datastores\DatastoreStatus;
use App\Deploy\EnvironmentVariables;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\EnvVarAvailability;
use App\Kube\FakeKubectl;
use App\Kube\KubectlResult;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class DatastoreAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const APPLY = ['apply', '--server-side', '--force-conflicts', '--field-manager=opencloud', '-f', '-'];

    private const RUSTFS_LOGIN = 'rc alias set local http://127.0.0.1:9000 "$RUSTFS_ACCESS_KEY" "$RUSTFS_SECRET_KEY" >/dev/null';

    private FakeKubectl $kubectl;

    private Environment $environment;

    private User $owner;

    /** @var list<string> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kubectl = FakeKubectl::install();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $this->environment = Environment::factory()->for(Application::factory()->for($cluster))->create();
        $this->owner = User::factory()->create();
        Str::createRandomStringsUsing(fn (int $length) => $length === 32 ? 'pass'.count($this->kubectl->calls) : Str::password($length));

        DatastoreAttachment::updated(function (DatastoreAttachment $attachment) {
            if ($attachment->wasChanged('status')) {
                $this->statuses[] = $attachment->status->value;
            }
        });
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();

        parent::tearDown();
    }

    public function test_mysql_gets_a_server_then_a_database_and_a_user_with_rights_on_that_database_only(): void
    {
        $attachment = $this->attach(DatastoreKind::Mysql);
        $datastore = $attachment->datastore->fresh();
        $id = $datastore->id;
        $env = $this->environment->id;

        $this->assertSame([DatastoreKind::Mysql, '8.4', DatastoreStatus::Ready, 'pass0'], [$datastore->kind, $datastore->version, $datastore->status, $datastore->root_password]);
        $this->assertSame(['provisioning', 'ready'], $this->statuses);
        $this->assertSame(["env_{$env}", "env_{$env}", 'pass0', DatastoreStatus::Ready, null], [$attachment->database_name, $attachment->username, $attachment->password, $attachment->fresh()->status, $attachment->fresh()->error]);
        $this->assertSame([
            self::APPLY,
            ['rollout', 'status', "statefulset/mysql-{$id}", '--namespace=oc-datastores', '--timeout=600s'],
            ['exec', '-i', "mysql-{$id}-0", '--namespace=oc-datastores', '--container=mysql', '--', 'bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertSame(['Namespace/oc-datastores', "Secret/mysql-{$id}", "Service/mysql-{$id}", "StatefulSet/mysql-{$id}"], $this->appliedNames());
        $this->assertSame(
            "CREATE DATABASE IF NOT EXISTS `env_{$env}`;\n".
            "CREATE USER IF NOT EXISTS 'env_{$env}'@'%' IDENTIFIED BY 'pass0';\n".
            "ALTER USER 'env_{$env}'@'%' IDENTIFIED BY 'pass0';\n".
            "GRANT ALL PRIVILEGES ON `env_{$env}`.* TO 'env_{$env}'@'%';\n",
            $this->kubectl->calls[2]['stdin'],
        );
        $this->assertSame([
            'DB_CONNECTION' => ['mysql', false],
            'DB_DATABASE' => ["env_{$env}", false],
            'DB_HOST' => ["mysql-{$id}.oc-datastores.svc.cluster.local", false],
            'DB_PASSWORD' => ['pass0', true],
            'DB_PORT' => ['3306', false],
            'DB_USERNAME' => ["env_{$env}", false],
        ], $this->datastoreEnv($attachment));
        $this->assertSame(['datastore.attached'], AuditEvent::pluck('action')->all());
        $this->assertStringNotContainsString('pass0', AuditEvent::all()->toJson());
    }

    public function test_postgres_gets_a_role_and_a_database_that_other_roles_cannot_connect_to(): void
    {
        $attachment = $this->attach(DatastoreKind::Postgres);
        $id = $attachment->datastore_id;
        $name = "env_{$this->environment->id}";

        $this->assertSame(
            ['exec', '-i', "postgres-{$id}-0", '--namespace=oc-datastores', '--container=postgres', '--', 'psql', '--username=postgres', '--dbname=postgres', '--set=ON_ERROR_STOP=1', '--quiet', '--no-psqlrc'],
            $this->kubectl->calls[2]['args'],
        );
        $this->assertSame(
            "SELECT 'CREATE ROLE \"{$name}\" LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$name}')\\gexec\n".
            "ALTER ROLE \"{$name}\" WITH LOGIN PASSWORD 'pass0';\n".
            "SELECT 'CREATE DATABASE \"{$name}\" OWNER \"{$name}\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$name}')\\gexec\n".
            "REVOKE ALL ON DATABASE \"{$name}\" FROM PUBLIC;\n",
            $this->kubectl->calls[2]['stdin'],
        );
        $this->assertSame([
            'DB_CONNECTION' => ['pgsql', false],
            'DB_DATABASE' => [$name, false],
            'DB_HOST' => ["postgres-{$id}.oc-datastores.svc.cluster.local", false],
            'DB_PASSWORD' => ['pass0', true],
            'DB_PORT' => ['5432', false],
            'DB_USERNAME' => [$name, false],
        ], $this->datastoreEnv($attachment));
    }

    public function test_rustfs_gets_a_bucket_and_a_user_whose_policy_allows_that_bucket_only(): void
    {
        $attachment = $this->attach(DatastoreKind::Rustfs);
        $id = $attachment->datastore_id;
        $name = "env-{$this->environment->id}";

        $this->assertSame(['Namespace/oc-datastores', "Secret/rustfs-{$id}", "Service/rustfs-{$id}", "StatefulSet/rustfs-{$id}"], $this->appliedNames());
        $this->assertSame(['exec', '-i', "rustfs-{$id}-0", '--namespace=oc-datastores', '--container=rc', '--', 'sh', '-c', implode("\n", [
            'set -e',
            'read -r secret',
            self::RUSTFS_LOGIN,
            "rc bucket create --ignore-existing local/{$name} >/dev/null",
            "printf '%s' '{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":[\"s3:*\"],\"Resource\":[\"arn:aws:s3:::{$name}\",\"arn:aws:s3:::{$name}/*\"]}]}' > /tmp/{$name}.json",
            "rc admin policy create local {$name} /tmp/{$name}.json >/dev/null",
            "rc admin user add local {$name} \"\$secret\" >/dev/null",
            "rc admin policy attach local {$name} --user {$name} >/dev/null",
        ])], $this->kubectl->calls[2]['args']);
        $this->assertSame("pass0\n", $this->kubectl->calls[2]['stdin']);
        $this->assertSame([
            'AWS_ACCESS_KEY_ID' => [$name, false],
            'AWS_BUCKET' => [$name, false],
            'AWS_DEFAULT_REGION' => ['us-east-1', false],
            'AWS_ENDPOINT' => ["http://rustfs-{$id}.oc-datastores.svc.cluster.local:9000", false],
            'AWS_SECRET_ACCESS_KEY' => ['pass0', true],
            'AWS_USE_PATH_STYLE_ENDPOINT' => ['true', false],
            'FILESYSTEM_DISK' => ['s3', false],
        ], $this->datastoreEnv($attachment));
    }

    public function test_valkey_runs_one_server_per_attachment(): void
    {
        $attachment = $this->attach(DatastoreKind::Valkey);
        $name = "valkey-{$attachment->id}";

        $this->assertNull($attachment->datastore->fresh()->root_password);
        $this->assertSame([null, null, DatastoreStatus::Ready], [$attachment->database_name, $attachment->username, $attachment->fresh()->status]);
        $this->assertSame([
            self::APPLY,
            self::APPLY,
            ['rollout', 'status', "deployment/{$name}", '--namespace=oc-datastores', '--timeout=300s'],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertSame(['Namespace/oc-datastores', "Secret/{$name}", "PersistentVolumeClaim/{$name}", "Service/{$name}", "Deployment/{$name}"], $this->appliedNames());
        $this->assertSame([
            'CACHE_STORE' => ['redis', false],
            'REDIS_CLIENT' => ['phpredis', false],
            'REDIS_HOST' => ["{$name}.oc-datastores.svc.cluster.local", false],
            'REDIS_PASSWORD' => ['pass0', true],
            'REDIS_PORT' => ['6379', false],
        ], $this->datastoreEnv($attachment));
    }

    public function test_a_failed_attach_keeps_the_error_without_the_password_and_a_second_attach_retries_it(): void
    {
        $this->kubectl->respond('exec', new KubectlResult(1, '', "psql:<stdin>:2: ERROR:  syntax error\nLINE 1: ALTER ROLE \"env_1\" WITH LOGIN PASSWORD 'pass0';"));

        $attachment = $this->attach(DatastoreKind::Postgres);

        $this->assertSame(['provisioning', 'failed'], $this->statuses);
        $this->assertSame(DatastoreStatus::Ready, $attachment->datastore->fresh()->status);
        $this->assertSame(
            "psql:<stdin>:2: ERROR:  syntax error\nLINE 1: ALTER ROLE \"env_1\" WITH LOGIN PASSWORD '********';",
            $attachment->fresh()->error,
        );

        $this->kubectl->respond('exec', new KubectlResult(0));
        $again = $this->attach(DatastoreKind::Postgres);

        $this->assertTrue($again->is($attachment));
        $this->assertSame(['provisioning', 'failed', 'provisioning', 'ready'], $this->statuses);
        $this->assertNull($attachment->fresh()->error);
    }

    public function test_a_server_that_does_not_start_fails_the_datastore_and_the_attachment(): void
    {
        $this->kubectl->respond('rollout status', new KubectlResult(1, '', 'error: timed out waiting for the condition'));

        $attachment = $this->attach(DatastoreKind::Mysql);

        $datastore = $attachment->datastore->fresh();
        $this->assertSame([DatastoreStatus::Failed, 'error: timed out waiting for the condition'], [$datastore->status, $datastore->error]);
        $this->assertSame([DatastoreStatus::Failed, 'error: timed out waiting for the condition'], [$attachment->fresh()->status, $attachment->fresh()->error]);
        $this->assertSame([], $this->kubectl->callsStartingWith('exec'));
    }

    public function test_an_environment_gets_one_database_and_environments_share_the_server(): void
    {
        $first = $this->attach(DatastoreKind::Mysql);

        try {
            $this->attach(DatastoreKind::Postgres);
            $this->fail('No exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a database. Detach it first.', $e->getMessage());
        }

        $other = Environment::factory()->for($this->environment->application)->create();
        $second = app(AttachDatastore::class)->handle($other, $this->owner, DatastoreKind::Mysql);

        $this->assertSame($first->datastore_id, $second->datastore_id);
        $this->assertSame(["env_{$this->environment->id}", "env_{$other->id}"], [$first->database_name, $second->database_name]);
    }

    public function test_a_user_value_beats_the_datastore_value_with_the_same_key(): void
    {
        app(SetEnvVar::class)->handle($this->environment, $this->owner, 'DB_HOST', 'db.example.com', false, EnvVarAvailability::Runtime);

        $this->attach(DatastoreKind::Mysql);

        $env = EnvironmentVariables::runtime($this->environment);
        $this->assertSame(['db.example.com', 'pass0'], [$env['DB_HOST'], $env['DB_PASSWORD']]);
    }

    public function test_detaching_mysql_drops_the_user_and_the_env_values_and_keeps_the_database(): void
    {
        $attachment = $this->attach(DatastoreKind::Mysql);
        app(SetEnvVar::class)->handle($this->environment, $this->owner, 'DB_HOST', 'db.example.com', false, EnvVarAvailability::Runtime);
        $this->kubectl->calls = [];
        $env = $this->environment->id;

        app(DetachDatastore::class)->handle($attachment, $this->owner);

        $this->assertSame(0, DatastoreAttachment::count());
        $this->assertSame([['DB_HOST', 'user']], EnvVar::all()->map(fn (EnvVar $var) => [$var->key, $var->source->value])->all());
        $this->assertSame([
            ['exec', '-i', "mysql-{$attachment->datastore_id}-0", '--namespace=oc-datastores', '--container=mysql', '--', 'bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertSame("DROP USER IF EXISTS 'env_{$env}'@'%';\n", $this->kubectl->calls[0]['stdin']);
        $this->assertSame('datastore.detached', AuditEvent::latest('id')->value('action'));
    }

    public function test_destroying_mysql_also_drops_the_database(): void
    {
        $attachment = $this->attach(DatastoreKind::Mysql);
        $this->kubectl->calls = [];
        $env = $this->environment->id;

        app(DetachDatastore::class)->destroy($attachment, null);

        $this->assertSame("DROP USER IF EXISTS 'env_{$env}'@'%';\nDROP DATABASE IF EXISTS `env_{$env}`;\n", $this->kubectl->calls[0]['stdin']);
        $this->assertSame('datastore.destroyed', AuditEvent::latest('id')->value('action'));
    }

    public function test_detaching_postgres_moves_the_tables_to_postgres_before_it_drops_the_role(): void
    {
        $attachment = $this->attach(DatastoreKind::Postgres);
        $name = "env_{$this->environment->id}";
        $this->kubectl->calls = [];

        app(DetachDatastore::class)->handle($attachment, $this->owner);

        $this->assertSame(
            "SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$name}') AS found \\gset\n".
            "\\if :found\n".
            "\\connect \"{$name}\"\n".
            "REASSIGN OWNED BY \"{$name}\" TO postgres;\n".
            "DROP OWNED BY \"{$name}\";\n".
            "\\connect postgres\n".
            "DROP ROLE \"{$name}\";\n".
            "\\endif\n",
            $this->kubectl->calls[0]['stdin'],
        );
    }

    public function test_destroying_postgres_drops_the_database_and_the_role(): void
    {
        $attachment = $this->attach(DatastoreKind::Postgres);
        $name = "env_{$this->environment->id}";
        $this->kubectl->calls = [];

        app(DetachDatastore::class)->destroy($attachment, $this->owner);

        $this->assertSame("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE);\nDROP ROLE IF EXISTS \"{$name}\";\n", $this->kubectl->calls[0]['stdin']);
    }

    public function test_detaching_rustfs_removes_the_user_and_the_policy_and_destroying_also_removes_the_bucket(): void
    {
        $name = "env-{$this->environment->id}";
        $script = fn (array $calls) => $calls[0]['args'][array_key_last($calls[0]['args'])];

        $attachment = $this->attach(DatastoreKind::Rustfs);
        $this->kubectl->calls = [];
        app(DetachDatastore::class)->handle($attachment, $this->owner);
        $detach = $script($this->kubectl->calls);

        $attachment = $this->attach(DatastoreKind::Rustfs);
        $this->kubectl->calls = [];
        app(DetachDatastore::class)->destroy($attachment, $this->owner);

        $this->assertSame(implode("\n", [
            'set -e',
            self::RUSTFS_LOGIN,
            "rc admin user rm local {$name} >/dev/null || [ $? -eq 5 ]",
            "rc admin policy rm local {$name} >/dev/null",
        ]), $detach);
        $this->assertSame(implode("\n", [
            'set -e',
            self::RUSTFS_LOGIN,
            "rc admin user rm local {$name} >/dev/null || [ $? -eq 5 ]",
            "rc admin policy rm local {$name} >/dev/null",
            "rc bucket remove --force local/{$name} >/dev/null || [ $? -eq 5 ]",
        ]), $script($this->kubectl->calls));
    }

    public function test_detaching_valkey_removes_the_server_and_destroying_also_removes_the_volume(): void
    {
        $attachment = $this->attach(DatastoreKind::Valkey);
        $name = "valkey-{$attachment->id}";
        $this->kubectl->calls = [];

        app(DetachDatastore::class)->handle($attachment, $this->owner);
        $detached = array_column($this->kubectl->calls, 'args');

        $attachment = $this->attach(DatastoreKind::Valkey);
        $this->kubectl->calls = [];
        app(DetachDatastore::class)->destroy($attachment, $this->owner);

        $this->assertSame([
            ['delete', 'deployment', $name, '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'service', $name, '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'secret', $name, '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
        ], $detached);
        $this->assertSame(
            ['deployment', 'service', 'secret', 'persistentvolumeclaim'],
            array_map(fn (array $call) => $call['args'][1], $this->kubectl->calls),
        );
    }

    private function attach(DatastoreKind $kind): DatastoreAttachment
    {
        return app(AttachDatastore::class)->handle($this->environment, $this->owner, $kind);
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        return array_map(fn (array $manifest) => "{$manifest['kind']}/{$manifest['metadata']['name']}", $this->kubectl->appliedManifests());
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function datastoreEnv(DatastoreAttachment $attachment): array
    {
        return $attachment->envVars()->orderBy('key')->get()
            ->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret]])
            ->all();
    }
}
