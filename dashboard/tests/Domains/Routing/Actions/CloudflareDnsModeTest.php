<?php

namespace Tests\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\AddDomain;
use App\Domains\Routing\Actions\RemoveDomain;
use App\Domains\Routing\Enums\DnsMode;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class CloudflareDnsModeTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const CF = 'https://api.cloudflare.com/client/v4';

    private const TOKEN = 'cf-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ProviderCredential::factory()->create([
            'provider' => 'cloudflare',
            'token' => self::TOKEN,
            'details' => ['zones' => [['id' => 'zone-1', 'name' => 'example.com'], ['id' => 'zone-2', 'name' => 'shop.example.com']]],
        ]);
    }

    public function test_it_creates_the_a_record_at_the_ingress_ip_and_stores_what_it_made(): void
    {
        Http::fake([
            self::CF.'/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            self::CF.'/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-9']]),
        ]);

        $domain = app(AddDomain::class)->execute($this->makeEnvironment(), User::factory()->owner()->create(), 'App.Example.com.', manageDns: true, proxied: true);

        $this->assertSame(DnsMode::Cloudflare, $domain->fresh()->dns_mode);
        $this->assertSame(['zone-1', 'rec-9'], [$domain->fresh()->cloudflare_zone_id, $domain->fresh()->cloudflare_record_id]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::CF.'/zones/zone-1/dns_records'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->data() === ['type' => 'A', 'name' => 'app.example.com', 'content' => '203.0.113.10', 'ttl' => 1, 'proxied' => true, 'comment' => 'Managed by opencloud']);
        Queue::assertPushed(CheckDomain::class);
        $this->assertSame(
            ['environment_id' => $domain->environment_id, 'hostname' => 'app.example.com', 'dns_mode' => 'cloudflare', 'proxied' => true],
            AuditEvent::where('action', 'domain.added')->sole()->context,
        );
    }

    public function test_it_updates_a_record_that_exists_and_remembers_it_did_not_create_it(): void
    {
        Http::fake([
            self::CF.'/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => [['id' => 'rec-old', 'content' => '198.51.100.1', 'proxied' => false]]]),
            self::CF.'/zones/zone-1/dns_records/rec-old' => Http::response(['success' => true, 'result' => ['id' => 'rec-old']]),
        ]);

        $domain = app(AddDomain::class)->execute($this->makeEnvironment(), User::factory()->owner()->create(), 'app.example.com', manageDns: true);

        $this->assertSame(DnsMode::Cloudflare, $domain->fresh()->dns_mode);
        $this->assertNull($domain->fresh()->cloudflare_record_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::CF.'/zones/zone-1/dns_records/rec-old'
            && $request->data()['content'] === '203.0.113.10'
            && $request->data()['proxied'] === false);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_the_longest_matching_zone_wins(): void
    {
        Http::fake([
            self::CF.'/zones/zone-2/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            self::CF.'/zones/zone-2/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        $domain = app(AddDomain::class)->execute($this->makeEnvironment(), User::factory()->owner()->create(), 'www.shop.example.com', manageDns: true);

        $this->assertSame('zone-2', $domain->fresh()->cloudflare_zone_id);
    }

    public function test_a_hostname_outside_every_zone_is_refused_and_nothing_is_stored(): void
    {
        Http::fake();

        try {
            app(AddDomain::class)->execute($this->makeEnvironment(), User::factory()->owner()->create(), 'app.other.org', manageDns: true);
            $this->fail('The action should refuse.');
        } catch (ValidationException $e) {
            $this->assertSame(['manage_dns' => ['The Cloudflare key does not cover the zone of this domain.']], $e->errors());
        }

        $this->assertSame(0, Domain::count());
        Http::assertNothingSent();
    }

    public function test_a_cloudflare_error_is_shown_and_nothing_is_stored(): void
    {
        Http::fake([
            self::CF.'/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            self::CF.'/zones/zone-1/dns_records' => Http::response(['success' => false, 'errors' => [['code' => 81053, 'message' => 'A CNAME record with that host already exists.']]], 400),
        ]);

        try {
            app(AddDomain::class)->execute($this->makeEnvironment(), User::factory()->owner()->create(), 'app.example.com', manageDns: true);
            $this->fail('The action should refuse.');
        } catch (ValidationException $e) {
            $this->assertSame(['manage_dns' => ['Cloudflare refused the DNS record: A CNAME record with that host already exists.']], $e->errors());
        }

        $this->assertSame(0, Domain::count());
    }

    public function test_manual_mode_stays_the_default_and_never_calls_cloudflare(): void
    {
        Http::fake();

        $domain = app(AddDomain::class)->execute($this->makeEnvironment(), User::factory()->owner()->create(), 'app.example.com');

        $this->assertSame(DnsMode::Manual, $domain->fresh()->dns_mode);
        Http::assertNothingSent();
    }

    public function test_removing_the_domain_deletes_the_record_it_created(): void
    {
        Http::fake([self::CF.'/zones/zone-1/dns_records/rec-9' => Http::response(['success' => true, 'result' => ['id' => 'rec-9']])]);
        $domain = Domain::factory()->for($this->makeEnvironment())->create([
            'hostname' => 'app.example.com',
            'dns_mode' => DnsMode::Cloudflare,
            'cloudflare_zone_id' => 'zone-1',
            'cloudflare_record_id' => 'rec-9',
        ]);

        app(RemoveDomain::class)->execute($domain, User::factory()->owner()->create());

        $this->assertSame(0, Domain::count());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::CF.'/zones/zone-1/dns_records/rec-9');
        Queue::assertPushed(SyncCaddy::class);
    }

    public function test_removing_a_domain_without_a_created_record_calls_nothing(): void
    {
        Http::fake();
        $domain = Domain::factory()->for($this->makeEnvironment())->create(['dns_mode' => DnsMode::Cloudflare]);

        app(RemoveDomain::class)->execute($domain, User::factory()->owner()->create());

        $this->assertSame(0, Domain::count());
        Http::assertNothingSent();
    }

    public function test_a_record_that_is_already_gone_does_not_block_the_removal(): void
    {
        Http::fake([self::CF.'/zones/zone-1/dns_records/rec-9' => Http::response(['success' => false, 'errors' => [['message' => 'Record does not exist']]], 404)]);
        $domain = Domain::factory()->for($this->makeEnvironment())->create(['cloudflare_zone_id' => 'zone-1', 'cloudflare_record_id' => 'rec-9']);

        app(RemoveDomain::class)->execute($domain, User::factory()->owner()->create());

        $this->assertSame(0, Domain::count());
    }

    public function test_a_refused_delete_keeps_the_domain(): void
    {
        Http::fake([self::CF.'/zones/zone-1/dns_records/rec-9' => Http::response(['success' => false, 'errors' => [['message' => 'Authentication error']]], 403)]);
        $domain = Domain::factory()->for($this->makeEnvironment())->create(['hostname' => 'app.example.com', 'cloudflare_zone_id' => 'zone-1', 'cloudflare_record_id' => 'rec-9']);

        $this->actingAs(User::factory()->owner()->create())->delete("/domains/{$domain->id}")
            ->assertSessionHasErrors(['domain' => 'Cloudflare did not delete the DNS record of app.example.com: Authentication error']);

        $this->assertSame(1, Domain::count());
    }

    public function test_the_domain_form_posts_the_dns_mode_and_the_page_lists_the_zones_without_the_token(): void
    {
        Http::fake([
            self::CF.'/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            self::CF.'/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-9']]),
        ]);
        $environment = $this->makeEnvironment();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/environments/{$environment->id}/domains", ['hostname' => 'app.example.com', 'manage_dns' => true, 'proxied' => false])->assertRedirect();

        $response = $this->actingAs($owner)->get("/environments/{$environment->id}");
        $response->assertInertia(fn (Assert $page) => $page
            ->where('cloudflare_zones', ['example.com', 'shop.example.com'])
            ->where('domains.0.dns_mode', 'cloudflare')
            ->where('domains.0.dns_status', DnsStatus::Pending->value));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_the_page_lists_no_zones_without_a_cloudflare_key(): void
    {
        ProviderCredential::query()->delete();
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('cloudflare_zones', []));
    }
}
