<?php

namespace Tests\Feature\Http;

use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\DashboardDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DashboardRootUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_domain_becomes_the_root_url_over_https(): void
    {
        config(['app.url' => 'http://203.0.113.10:8000']);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::Ok]);

        DashboardDomain::applyRootUrl();

        $this->assertSame('https://admin.example.com', config('app.url'));
        $this->assertSame('https://admin.example.com/login', URL::to('/login'));
        $this->assertSame('https://admin.example.com/login', route('login'));
    }

    public function test_a_domain_that_did_not_pass_keeps_the_app_url(): void
    {
        config(['app.url' => 'http://203.0.113.10:8000']);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::Missing]);

        DashboardDomain::applyRootUrl();

        $this->assertSame('http://203.0.113.10:8000', config('app.url'));
    }

    public function test_the_app_url_stays_before_the_table_exists(): void
    {
        config(['app.url' => 'http://203.0.113.10:8000']);
        Schema::drop('dashboard_domains');

        DashboardDomain::applyRootUrl();

        $this->assertSame('http://203.0.113.10:8000', config('app.url'));
    }

    public function test_the_proxy_headers_from_caddy_set_the_scheme_and_host(): void
    {
        $this->get('/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'admin.example.com'])->assertOk();

        $request = request();
        $this->assertSame('https', $request->getScheme());
        $this->assertSame('admin.example.com', $request->getHost());
    }
}
