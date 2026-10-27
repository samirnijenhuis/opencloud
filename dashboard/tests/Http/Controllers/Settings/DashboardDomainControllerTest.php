<?php

namespace Tests\Http\Controllers\Settings;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckDashboardDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\DashboardDomain;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardDomainControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_opens_and_changes_the_address(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/settings/dashboard-address')->assertForbidden();
        $this->actingAs($admin)->put('/settings/dashboard-address', ['hostname' => 'admin.example.com'])->assertForbidden();
        $this->actingAs($admin)->post('/settings/dashboard-address/check')->assertForbidden();
        $this->actingAs($admin)->delete('/settings/dashboard-address')->assertForbidden();
        $this->assertSame(0, DashboardDomain::count());
    }

    public function test_the_page_shows_the_ip_address_until_a_domain_passes(): void
    {
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => '203.0.113.10']);

        $this->actingAs(User::factory()->owner()->create())->get('/settings/dashboard-address')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/dashboard-address')
                ->where('address', 'http://203.0.113.10:8000')
                ->where('ingressIp', '203.0.113.10')
                ->where('domain', null));
    }

    public function test_the_page_shows_the_dns_state_of_the_domain(): void
    {
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => '203.0.113.10']);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::WRONG, 'dns_found' => ['198.51.100.1']]);

        $this->actingAs(User::factory()->owner()->create())->get('/settings/dashboard-address')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('address', 'http://203.0.113.10:8000')
                ->where('domain.hostname', 'admin.example.com')
                ->where('domain.dns_status', 'wrong')
                ->where('domain.dns_found', ['198.51.100.1']));
    }

    public function test_the_page_shows_the_domain_as_the_address_once_it_passes(): void
    {
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => '203.0.113.10']);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::OK]);

        $this->actingAs(User::factory()->owner()->create())->get('/settings/dashboard-address')
            ->assertInertia(fn (Assert $page) => $page->where('address', 'https://admin.example.com')->where('domain.dns_status', 'ok'));
    }

    public function test_setting_a_domain_starts_the_dns_check_and_records_an_audit_event(): void
    {
        Queue::fake();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put('/settings/dashboard-address', ['hostname' => ' Admin.Example.com. '])->assertRedirect();

        $domain = DashboardDomain::sole();
        $this->assertSame(['admin.example.com', DnsStatus::PENDING], [$domain->hostname, $domain->dns_status]);
        Queue::assertPushed(CheckDashboardDomain::class, 1);
        $this->assertSame('dashboard_domain.set', AuditEvent::sole()->action);
    }

    public function test_a_new_domain_replaces_the_old_one(): void
    {
        Queue::fake();
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL]);
        DashboardDomain::create(['hostname' => 'old.example.com', 'dns_status' => DnsStatus::OK]);

        $this->actingAs(User::factory()->owner()->create())->put('/settings/dashboard-address', ['hostname' => 'new.example.com'])->assertRedirect();

        $this->assertSame(['new.example.com'], DashboardDomain::pluck('hostname')->all());
        Queue::assertPushed(SyncCaddy::class, 1);
    }

    public function test_the_hostname_must_be_valid_and_unused(): void
    {
        Queue::fake();
        $owner = User::factory()->owner()->create();
        Domain::factory()->create(['hostname' => 'taken.example.com']);

        $this->actingAs($owner)->put('/settings/dashboard-address', ['hostname' => 'not a host'])->assertSessionHasErrors('hostname');
        $this->actingAs($owner)->put('/settings/dashboard-address', ['hostname' => 'taken.example.com'])->assertSessionHasErrors('hostname');
        $this->assertSame(0, DashboardDomain::count());
    }

    public function test_an_application_cannot_take_the_dashboard_hostname(): void
    {
        DashboardDomain::create(['hostname' => 'admin.example.com']);
        $environment = Environment::factory()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->post("/environments/{$environment->id}/domains", ['hostname' => 'admin.example.com'])
            ->assertSessionHasErrors('hostname');
    }

    public function test_check_again_queues_a_check(): void
    {
        Queue::fake();
        DashboardDomain::create(['hostname' => 'admin.example.com']);

        $this->actingAs(User::factory()->owner()->create())->post('/settings/dashboard-address/check')->assertRedirect();

        Queue::assertPushed(CheckDashboardDomain::class, 1);
    }

    public function test_removing_the_domain_brings_back_the_ip_address_and_the_default_root_url(): void
    {
        Queue::fake();
        config(['app.url' => 'http://localhost']);
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => '203.0.113.10']);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::OK]);

        $this->actingAs(User::factory()->owner()->create())->delete('/settings/dashboard-address')->assertRedirect();

        $this->assertSame(0, DashboardDomain::count());
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $sync) => $sync->cluster->is($local));
        $this->assertSame('dashboard_domain.removed', AuditEvent::sole()->action);

        DashboardDomain::applyRootUrl();
        $this->assertSame('http://localhost', config('app.url'));
        $this->assertSame('http://localhost/login', URL::to('/login'));
    }
}
