<?php

namespace Tests\Domains\Environments\Models;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\Tool;
use App\Domains\Environments\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailpit_always_runs_and_phpmyadmin_runs_only_with_a_default_mysql_database(): void
    {
        $environment = Environment::factory()->create(['tools' => [Tool::MAILPIT, Tool::PHPMYADMIN]]);
        $this->assertSame([Tool::MAILPIT], $environment->runningTools());

        $this->attach($environment, DatastoreKind::POSTGRES, 'main', isDefault: true);
        $this->attach($environment, DatastoreKind::MYSQL, 'reports', isDefault: false);
        $this->assertSame([Tool::MAILPIT], $environment->fresh()->runningTools());

        $other = Environment::factory()->for($environment->application)->create(['tools' => [Tool::MAILPIT, Tool::PHPMYADMIN]]);
        $this->attach($other, DatastoreKind::MYSQL, 'main', isDefault: true);
        $this->assertSame([Tool::MAILPIT, Tool::PHPMYADMIN], $other->runningTools());
    }

    public function test_an_environment_without_tools_runs_none_also_with_a_default_mysql_database(): void
    {
        $environment = Environment::factory()->create();
        $this->attach($environment, DatastoreKind::MYSQL, 'main', isDefault: true);

        $this->assertSame([], $environment->runningTools());
    }

    private function attach(Environment $environment, DatastoreKind $kind, string $name, bool $isDefault): void
    {
        DatastoreAttachment::factory()->for(Datastore::factory()->create(['kind' => $kind]))->for($environment)->create([
            'name' => $name,
            'is_default' => $isDefault,
            'host' => "{$kind->value}-1.oc-datastores.svc.cluster.local",
            'port' => 3306,
        ]);
    }
}
