<?php

namespace Tests\Domains\Environments\Models;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailpit_always_runs_and_phpmyadmin_runs_only_with_a_default_mysql_database(): void
    {
        $environment = $this->withBothTools(Environment::factory()->create());
        $this->assertSame([ToolKind::MAILPIT], $this->runningKinds($environment));

        $this->attach($environment, DatastoreKind::POSTGRES, 'main', isDefault: true);
        $this->attach($environment, DatastoreKind::MYSQL, 'reports', isDefault: false);
        $this->assertSame([ToolKind::MAILPIT], $this->runningKinds($environment->fresh()));

        $other = $this->withBothTools(Environment::factory()->for($environment->application)->create());
        $this->attach($other, DatastoreKind::MYSQL, 'main', isDefault: true);
        $this->assertSame([ToolKind::MAILPIT, ToolKind::PHPMYADMIN], $this->runningKinds($other));
    }

    public function test_an_environment_without_tools_runs_none_also_with_a_default_mysql_database(): void
    {
        $environment = Environment::factory()->create();
        $this->attach($environment, DatastoreKind::MYSQL, 'main', isDefault: true);

        $this->assertSame([], $environment->runningTools());
    }

    private function withBothTools(Environment $environment): Environment
    {
        foreach ([ToolKind::MAILPIT, ToolKind::PHPMYADMIN] as $kind) {
            Tool::factory()->for($environment)->create(['kind' => $kind]);
        }

        return $environment;
    }

    /** @return list<ToolKind> */
    private function runningKinds(Environment $environment): array
    {
        return array_map(fn (Tool $tool) => $tool->kind, $environment->runningTools());
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
