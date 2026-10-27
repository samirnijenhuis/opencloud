<?php

namespace Tests\Console\Commands;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckProviderLinksTest extends TestCase
{
    public function test_it_passes_when_each_link_answers_2xx_or_3xx(): void
    {
        Http::fake([
            'cloud.digitalocean.com/*' => Http::response('', 302),
            'console.hetzner.cloud/*' => Http::response('', 200),
            'developers.cloudflare.com/*' => Http::response('', 200),
        ]);

        $this->artisan('opencloud:check-provider-links')
            ->expectsOutput('OK   DigitalOcean 302 https://cloud.digitalocean.com/account/api/tokens/new')
            ->expectsOutput('OK   Hetzner 200 https://console.hetzner.cloud/projects')
            ->assertSuccessful();
    }

    public function test_it_fails_on_a_404_and_names_the_provider(): void
    {
        Http::fake([
            'cloud.digitalocean.com/*' => Http::response('', 404),
            '*' => Http::response('', 200),
        ]);

        $this->artisan('opencloud:check-provider-links')
            ->expectsOutput('FAIL DigitalOcean 404 https://cloud.digitalocean.com/account/api/tokens/new')
            ->assertFailed();
    }
}
