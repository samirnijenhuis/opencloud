<?php

namespace Tests\Domains\CloudProviders\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\CloudProviders\Actions\DeleteProviderCredential;
use App\Domains\CloudProviders\Actions\StoreProviderCredential;
use App\Domains\CloudProviders\Enums\CheckStatus;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderCredentialActionsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'dop_v1_supersecretvalue';

    public function test_a_working_key_is_stored_encrypted_and_audited_without_the_key(): void
    {
        $this->fakeDigitalOcean();
        $owner = User::factory()->owner()->create();

        $result = app(StoreProviderCredential::class)->execute($owner, 'digitalocean', '  '.self::SECRET."\n");

        $this->assertSame(CheckStatus::OK, $result->status);
        $credential = ProviderCredential::sole();
        $this->assertSame(self::SECRET, $credential->token);
        $this->assertNotNull($credential->checked_at);
        $this->assertSame([], $credential->missing_rights);
        $this->assertStringNotContainsString(self::SECRET, DB::table('provider_credentials')->value('token'));

        $event = AuditEvent::where('action', 'provider_credential.stored')->sole();
        $this->assertSame(['provider' => 'digitalocean', 'check' => 'ok', 'missing_rights' => []], $event->context);
        $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table('audit_events')->get()));
    }

    public function test_an_invalid_key_is_not_stored(): void
    {
        Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Unable to authenticate you'], 401)]);

        $result = app(StoreProviderCredential::class)->execute(User::factory()->owner()->create(), 'digitalocean', self::SECRET);

        $this->assertSame(CheckStatus::INVALID, $result->status);
        $this->assertSame(0, ProviderCredential::count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_missing_required_right_keeps_the_key_out(): void
    {
        $this->fakeDigitalOcean(registry: 403);

        $result = app(StoreProviderCredential::class)->execute(User::factory()->owner()->create(), 'digitalocean', self::SECRET);

        $this->assertSame(['registry'], $result->missing);
        $this->assertSame(0, ProviderCredential::count());
    }

    public function test_a_missing_optional_right_still_stores_the_key_and_records_the_gap(): void
    {
        $this->fakeDigitalOcean(vpcs: 403);

        $result = app(StoreProviderCredential::class)->execute(User::factory()->owner()->create(), 'digitalocean', self::SECRET);

        $this->assertSame(CheckStatus::MISSING_RIGHTS, $result->status);
        $this->assertSame(['vpc'], ProviderCredential::sole()->missing_rights);
    }

    public function test_a_key_without_the_database_right_is_stored_and_records_the_gap(): void
    {
        $this->fakeDigitalOcean(databases: 403);

        $result = app(StoreProviderCredential::class)->execute(User::factory()->owner()->create(), 'digitalocean', self::SECRET);

        $this->assertSame(CheckStatus::MISSING_RIGHTS, $result->status);
        $this->assertSame(['database'], ProviderCredential::sole()->missing_rights);
    }

    public function test_pasting_again_replaces_the_key_of_that_provider(): void
    {
        $this->fakeDigitalOcean();
        $owner = User::factory()->owner()->create();
        $store = app(StoreProviderCredential::class);

        $store->execute($owner, 'digitalocean', self::SECRET);
        $store->execute($owner, 'digitalocean', 'dop_v1_second');

        $this->assertSame('dop_v1_second', ProviderCredential::sole()->token);
    }

    public function test_the_cloudflare_zones_are_kept_with_the_key(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
            'https://api.cloudflare.com/client/v4/zones/*/dns_records*' => Http::response(['success' => true, 'result' => []]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [['id' => 'z1', 'name' => 'example.com']], 'result_info' => ['total_pages' => 1]]),
        ]);

        app(StoreProviderCredential::class)->execute(User::factory()->owner()->create(), 'cloudflare', 'cf-token');

        $this->assertSame(['zones' => [['id' => 'z1', 'name' => 'example.com']]], ProviderCredential::sole()->details);
    }

    public function test_a_message_that_echoes_the_key_is_cleaned(): void
    {
        Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Bad token '.self::SECRET], 401)]);

        $result = app(StoreProviderCredential::class)->execute(User::factory()->owner()->create(), 'digitalocean', self::SECRET);

        $this->assertSame('Bad token [hidden]', $result->message);
    }

    public function test_deleting_removes_the_key_and_audits_the_provider(): void
    {
        $credential = ProviderCredential::factory()->create(['provider' => 'hetzner']);

        app(DeleteProviderCredential::class)->execute($credential, User::factory()->owner()->create());

        $this->assertSame(0, ProviderCredential::count());
        $this->assertSame(['provider' => 'hetzner'], AuditEvent::where('action', 'provider_credential.deleted')->sole()->context);
    }

    private function fakeDigitalOcean(int $registry = 200, int $vpcs = 200, int $databases = 200, int $spacesKeys = 200): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['email' => 'me@example.com']]),
            'https://api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([]),
            'https://api.digitalocean.com/v2/registry' => Http::response(['message' => 'forbidden'], $registry),
            'https://api.digitalocean.com/v2/vpcs*' => Http::response(['message' => 'forbidden'], $vpcs),
            'https://api.digitalocean.com/v2/databases*' => Http::response(['message' => 'forbidden'], $databases),
            'https://api.digitalocean.com/v2/spaces/keys*' => Http::response(['message' => 'forbidden'], $spacesKeys),
        ]);
    }
}
