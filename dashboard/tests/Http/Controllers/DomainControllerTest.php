<?php

namespace Tests\Http\Controllers;

use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\AddDomain;
use App\Domains\Routing\Actions\RemoveDomain;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class DomainControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_page_shows_the_dns_state_and_the_ingress_ip(): void
    {
        $environment = $this->makeEnvironment();
        Domain::factory()->for($environment)->create(['hostname' => 'a.example.com', 'dns_status' => DnsStatus::WRONG, 'dns_found' => ['198.51.100.7']]);
        Domain::factory()->for($environment)->create(['hostname' => 'b.example.com', 'dns_status' => DnsStatus::OK, 'certificate_status' => 'issued']);

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/show')
                ->where('cluster.ingress_ip', '203.0.113.10')
                ->where('can.manage_domains', true)
                ->where('domains.0.hostname', 'a.example.com')
                ->where('domains.0.dns_status', 'wrong')
                ->where('domains.0.dns_found', ['198.51.100.7'])
                ->where('domains.1.dns_status', 'ok')
                ->where('domains.1.certificate_status', 'issued')
                ->where('domains.1.dns_found', []));
    }

    public function test_a_developer_sees_the_domains_but_cannot_change_them(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(false, 'staging');
        $domain = Domain::factory()->for($environment)->create();
        $developer = User::factory()->create();

        $this->actingAs($developer)->get("/environments/{$environment->id}")->assertInertia(fn (Assert $page) => $page->where('can.manage_domains', false));
        $this->actingAs($developer)->post("/environments/{$environment->id}/domains", ['hostname' => 'x.example.com'])->assertForbidden();
        $this->actingAs($developer)->post("/domains/{$domain->id}/check")->assertForbidden();
        $this->actingAs($developer)->post("/domains/{$domain->id}/primary")->assertForbidden();
        $this->actingAs($developer)->delete("/domains/{$domain->id}")->assertForbidden();

        $this->assertSame(1, Domain::count());
        $this->assertFalse($domain->fresh()->is_primary);
        Queue::assertNothingPushed();
    }

    public function test_it_adds_a_domain_through_the_action_with_a_clean_host_name(): void
    {
        $environment = $this->makeEnvironment();
        $admin = User::factory()->admin()->create();

        $this->mock(AddDomain::class)->shouldReceive('execute')->once()
            ->withArgs(fn ($given, User $by, string $hostname) => $given->is($environment) && $by->is($admin) && $hostname === 'shop.example.com');

        $this->actingAs($admin)->post("/environments/{$environment->id}/domains", ['hostname' => ' https://Shop.Example.com/ '])->assertRedirect();
    }

    public function test_adding_a_domain_starts_a_dns_check(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->admin()->create())->post("/environments/{$environment->id}/domains", ['hostname' => 'shop.example.com'])->assertRedirect();

        $this->assertSame('shop.example.com', Domain::sole()->hostname);
        Queue::assertPushed(CheckDomain::class);
    }

    public function test_the_host_name_is_validated(): void
    {
        $environment = $this->makeEnvironment();
        Domain::factory()->for($environment)->create(['hostname' => 'taken.example.com']);
        $admin = User::factory()->admin()->create();

        foreach (['', 'localhost', 'no spaces.com', '-bad.example.com', 'taken.example.com'] as $hostname) {
            $this->actingAs($admin)->post("/environments/{$environment->id}/domains", ['hostname' => $hostname])->assertSessionHasErrors('hostname');
        }

        $this->assertSame(1, Domain::count());
    }

    public function test_check_again_dispatches_the_check_job_for_that_domain(): void
    {
        Queue::fake();
        $domain = Domain::factory()->for($this->makeEnvironment())->create();

        $this->actingAs(User::factory()->admin()->create())->post("/domains/{$domain->id}/check")->assertRedirect();

        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->is($domain));
    }

    public function test_an_admin_makes_a_domain_primary_and_the_page_marks_it(): void
    {
        $environment = $this->makeEnvironment();
        Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com']);
        $domain = Domain::factory()->for($environment)->create(['hostname' => 'www.shop.example.com']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/environments/{$environment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('domains.0.primary', true)->where('domains.1.primary', false));

        $this->actingAs($admin)->post("/domains/{$domain->id}/primary")->assertRedirect();

        $this->assertTrue($domain->fresh()->is_primary);
        $this->actingAs($admin)->get("/environments/{$environment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('domains.0.primary', false)->where('domains.1.primary', true));
    }

    public function test_it_removes_a_domain_through_the_action(): void
    {
        $domain = Domain::factory()->for($this->makeEnvironment())->create();
        $admin = User::factory()->admin()->create();

        $this->mock(RemoveDomain::class)->shouldReceive('execute')->once()->withArgs(fn (Domain $given, User $by) => $given->is($domain) && $by->is($admin));

        $this->actingAs($admin)->delete("/domains/{$domain->id}")->assertRedirect();
    }

    public function test_removing_a_domain_syncs_caddy(): void
    {
        Queue::fake();
        $domain = Domain::factory()->for($this->makeEnvironment())->create();

        $this->actingAs(User::factory()->admin()->create())->delete("/domains/{$domain->id}")->assertRedirect();

        $this->assertSame(0, Domain::count());
        Queue::assertPushed(SyncCaddy::class);
    }
}
