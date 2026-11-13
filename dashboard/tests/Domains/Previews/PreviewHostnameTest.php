<?php

namespace Tests\Domains\Previews;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Previews\PreviewHostname;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewHostnameTest extends TestCase
{
    use RefreshDatabase;

    private function application(?string $pattern, ?string $ip = '203.0.113.10', string $name = 'Shop'): Application
    {
        return Application::factory()->for(Cluster::factory()->create(['ingress_ip' => $ip]))->create(['name' => $name, 'preview_domain' => $pattern]);
    }

    public function test_the_pattern_of_the_owner_wins(): void
    {
        $application = $this->application('pr-{pr_number}.previews.example.com');

        $this->assertSame('pr-{pr_number}.previews.example.com', (new PreviewHostname)->pattern($application));
        $this->assertSame('pr-12.previews.example.com', (new PreviewHostname)->for($application, 12));
    }

    public function test_without_a_pattern_the_free_address_has_the_slug_and_the_ingress_ip(): void
    {
        $application = $this->application(null);

        $this->assertSame('pr-{pr_number}-shop.203.0.113.10.sslip.io', (new PreviewHostname)->pattern($application));
        $this->assertSame('pr-7-shop.203.0.113.10.sslip.io', (new PreviewHostname)->for($application, 7));
    }

    public function test_the_slug_keeps_50_characters_and_no_trailing_dash(): void
    {
        $application = $this->application(null, name: str_repeat('a', 49).' b'.str_repeat('c', 20));

        $this->assertSame('pr-3-'.str_repeat('a', 49).'.203.0.113.10.sslip.io', (new PreviewHostname)->for($application, 3));
    }

    public function test_without_a_pattern_and_an_ingress_ip_there_is_no_address(): void
    {
        $application = $this->application(null, ip: null);

        $this->assertNull((new PreviewHostname)->pattern($application));
        $this->assertNull((new PreviewHostname)->for($application, 12));
    }
}
