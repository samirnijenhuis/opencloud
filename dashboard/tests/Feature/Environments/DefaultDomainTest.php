<?php

namespace Tests\Feature\Environments;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class DefaultDomainTest extends TestCase
{
    use RefreshDatabase;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeProcesses::install()->lsRemote = str_repeat('a', 40)."\trefs/heads/main\n";
        $this->cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);
    }

    public function test_a_new_application_gets_its_free_domain_after_the_domains_it_asks_for(): void
    {
        $owner = User::factory()->owner()->create();

        $application = $this->create('My Shop', ['domains' => ['shop.example.com']], $owner);

        $production = $application->environments()->where('name', 'production')->sole();
        $this->assertSame(
            [['shop.example.com', false], ['my-shop-production.203.0.113.10.sslip.io', true]],
            $production->domains()->orderBy('id')->get()->map(fn (Domain $domain) => [$domain->hostname, $domain->generated])->all(),
        );
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->hostname === 'my-shop-production.203.0.113.10.sslip.io');
        $this->assertSame(
            ['environment_id' => $production->id, 'hostname' => 'my-shop-production.203.0.113.10.sslip.io', 'generated' => true],
            AuditEvent::where('action', 'domain.added')->orderByDesc('id')->first()->context,
        );
        $this->assertSame(0, $application->environments()->where('kind', EnvironmentKind::PreviewTemplate)->sole()->domains()->count());
        $this->actingAs($owner)->get("/environments/{$production->id}")
            ->assertInertia(fn (Assert $page) => $page->where('domains.0.generated', false)->where('domains.1.generated', true));
    }

    public function test_a_second_application_with_the_same_name_gets_its_id_in_the_name(): void
    {
        $this->create('shop');
        $second = $this->create('Shop');

        $this->assertSame(
            ['shop-production.203.0.113.10.sslip.io', "shop-{$second->id}-production.203.0.113.10.sslip.io"],
            Domain::orderBy('id')->pluck('hostname')->all(),
        );
    }

    public function test_a_cluster_without_an_ipv4_ingress_address_gives_no_free_domain(): void
    {
        $this->cluster->update(['ingress_ip' => null]);
        $this->create('shop');
        $this->cluster->update(['ingress_ip' => 'abc123.elb.amazonaws.com']);
        $this->create('blog');

        $this->assertSame(0, Domain::count());
    }

    public function test_the_command_adds_the_free_domain_once_to_each_permanent_environment(): void
    {
        $application = Application::factory()->for($this->cluster)->create(['name' => 'shop']);
        $production = Environment::factory()->for($application)->create(['name' => 'production']);
        $staging = Environment::factory()->for($application)->create(['name' => 'staging']);
        Environment::factory()->for($application)->create(['name' => 'pr-4', 'kind' => EnvironmentKind::Preview]);
        Domain::factory()->for($staging)->create(['hostname' => 'staging.example.com']);

        $this->artisan('opencloud:default-domains')
            ->expectsOutput('Added shop-production.203.0.113.10.sslip.io to shop production.')
            ->expectsOutput('Added shop-staging.203.0.113.10.sslip.io to shop staging.')
            ->assertSuccessful();
        $this->artisan('opencloud:default-domains')->doesntExpectOutputToContain('Added')->assertSuccessful();

        $this->assertSame(
            [
                [$staging->id, 'staging.example.com', false],
                [$production->id, 'shop-production.203.0.113.10.sslip.io', true],
                [$staging->id, 'shop-staging.203.0.113.10.sslip.io', true],
            ],
            Domain::orderBy('id')->get()->map(fn (Domain $domain) => [$domain->environment_id, $domain->hostname, $domain->generated])->all(),
        );
        Queue::assertPushed(CheckDomain::class, 2);
    }

    /** @param  array<string, mixed>  $data */
    private function create(string $name, array $data = [], ?User $by = null): Application
    {
        return app(CreateApplication::class)->handle($by ?? User::factory()->owner()->create(), [
            'name' => $name,
            'repository' => 'acme/'.strtolower($name),
            'cluster_id' => $this->cluster->id,
            'database' => 'none',
            ...$data,
        ]);
    }
}
