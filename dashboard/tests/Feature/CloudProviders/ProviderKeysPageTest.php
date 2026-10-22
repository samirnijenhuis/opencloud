<?php

namespace Tests\Feature\CloudProviders;

use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderKeysPageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'dop_v1_supersecretvalue';

    public function test_only_the_owner_sees_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/settings/providers')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/settings/providers')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get('/settings/providers')->assertOk();
    }

    public function test_it_shows_each_guide_and_the_state_of_a_stored_key_without_the_key(): void
    {
        $this->travelTo('2026-10-23 12:00:00');
        ProviderCredential::factory()->create(['provider' => 'digitalocean', 'token' => self::SECRET, 'checked_at' => now()->subMinutes(2), 'missing_rights' => ['vpc']]);

        $response = $this->actingAs(User::factory()->owner()->create())->get('/settings/providers');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/provider-keys')
            ->has('providers', 4)
            ->where('providers.0.key', 'digitalocean')
            ->where('providers.0.url', 'https://cloud.digitalocean.com/account/api/tokens/new')
            ->where('providers.0.stored.checked_at', '2026-10-23T11:58:00+00:00')
            ->where('providers.0.stored.missing_rights', ['vpc: read'])
            ->where('providers.1.stored', null)
            ->where('providers.2.key', 'aws')
            ->where('providers.2.url', null)
            ->where('providers.2.command', fn ($command) => str_starts_with($command, 'ARN=$(aws iam create-policy')));

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString(base64_encode(self::SECRET), $response->getContent());
    }

    public function test_pasting_a_key_checks_it_and_shows_the_result_without_the_key(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['email' => 'me@example.com']]),
            'https://api.digitalocean.com/v2/*' => Http::response([]),
        ]);
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->followingRedirects()->put('/settings/providers/digitalocean', ['token' => self::SECRET]);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('providers.0.result.status', 'ok')
            ->where('providers.0.result.saved', true)
            ->where('providers.0.result.message', 'The token works for the account me@example.com.')
            ->where('providers.0.stored.id', ProviderCredential::sole()->id));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_an_invalid_key_shows_the_message_and_is_not_saved(): void
    {
        Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Unable to authenticate you'], 401)]);

        $this->actingAs(User::factory()->owner()->create())->followingRedirects()->put('/settings/providers/digitalocean', ['token' => self::SECRET])
            ->assertInertia(fn (Assert $page) => $page
                ->where('providers.0.result.status', 'invalid')
                ->where('providers.0.result.saved', false)
                ->where('providers.0.result.message', 'Unable to authenticate you')
                ->where('providers.0.stored', null));
        $this->assertSame(0, ProviderCredential::count());
    }

    public function test_a_non_owner_cannot_paste_check_or_remove_a_key(): void
    {
        Http::fake();
        $credential = ProviderCredential::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/providers/digitalocean', ['token' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post("/settings/provider-credentials/{$credential->id}/check")->assertForbidden();
        $this->actingAs($admin)->delete("/settings/provider-credentials/{$credential->id}")->assertForbidden();

        $this->assertSame(1, ProviderCredential::count());
        Http::assertNothingSent();
    }

    public function test_an_unknown_provider_is_a_404(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->owner()->create())->put('/settings/providers/linode', ['token' => 'x'])->assertNotFound();
    }

    public function test_the_owner_checks_again_and_removes_a_key(): void
    {
        Http::fake(['https://api.hetzner.cloud/v1/servers*' => Http::response(['servers' => []])]);
        $credential = ProviderCredential::factory()->create(['provider' => 'hetzner', 'token' => 'h-token']);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/settings/provider-credentials/{$credential->id}/check")->assertRedirect('/settings/providers');
        $this->assertNotNull($credential->fresh()->checked_at);

        $this->actingAs($owner)->delete("/settings/provider-credentials/{$credential->id}")->assertRedirect('/settings/providers');
        $this->assertSame(0, ProviderCredential::count());
    }

    public function test_the_sidebar_flag_is_true_for_the_owner_only(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/settings/members')->assertInertia(fn (Assert $page) => $page->where('can.manage_credentials', true));
        $this->actingAs(User::factory()->admin()->create())->get('/settings/members')->assertInertia(fn (Assert $page) => $page->where('can.manage_credentials', false));
    }
}
