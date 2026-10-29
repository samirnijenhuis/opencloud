<?php

namespace Tests\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\RemoveDomain;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use App\Domains\Routing\PrimaryDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class RemoveDomainTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_removing_the_primary_domain_moves_the_primary_to_the_rule_and_the_audit_event_says_so(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();
        Domain::factory()->for($environment)->create(['hostname' => 'shop-production.203.0.113.10.sslip.io', 'generated' => true, 'dns_status' => DnsStatus::OK, 'certificate_status' => 'issued']);
        $live = Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com', 'dns_status' => DnsStatus::OK, 'certificate_status' => 'issued']);
        $chosen = Domain::factory()->for($environment)->create(['hostname' => 'new.example.com', 'is_primary' => true]);
        $remove = app(RemoveDomain::class);
        $admin = User::factory()->admin()->create();

        $remove->execute($chosen, $admin);
        $this->assertSame('shop.example.com', PrimaryDomain::of($environment->domains()->get())->hostname);

        $remove->execute($live, $admin);
        $this->assertSame('shop-production.203.0.113.10.sslip.io', PrimaryDomain::of($environment->domains()->get())->hostname);

        $this->assertSame(
            [['hostname' => 'new.example.com', 'primary' => true], ['hostname' => 'shop.example.com', 'primary' => true]],
            AuditEvent::query()->where('action', 'domain.removed')->orderBy('id')->pluck('context')->all(),
        );
    }

    public function test_the_audit_event_of_a_domain_that_is_not_primary_says_nothing_about_the_primary(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();
        Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com']);
        $other = Domain::factory()->for($environment)->create(['hostname' => 'www.shop.example.com']);

        app(RemoveDomain::class)->execute($other, User::factory()->admin()->create());

        $this->assertSame([['hostname' => 'www.shop.example.com']], AuditEvent::query()->pluck('context')->all());
    }
}
