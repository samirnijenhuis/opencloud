<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClusterAndAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_clusters_page_lists_clusters_and_never_the_kubeconfig(): void
    {
        Cluster::factory()->create([
            'name' => 'local',
            'kind' => ClusterKind::LOCAL,
            'ingress_ip' => '203.0.113.10',
            'status' => 'ready',
            'parts_version' => '1.2.0',
            'kubeconfig' => 'apiVersion: v1 SUPER_PRIVATE_KEY_DATA',
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/clusters')->assertOk();

        $this->assertStringNotContainsString('SUPER_PRIVATE_KEY_DATA', $response->getContent());
        $response->assertInertia(fn (Assert $page) => $page
            ->component('clusters/index')
            ->where('clusters.0', ['id' => 1, 'name' => 'local', 'kind' => 'local', 'ingress_ip' => '203.0.113.10', 'status' => 'ready', 'parts_version' => '1.2.0']));
    }

    public function test_the_audit_log_is_for_owners_and_admins(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/audit')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/settings/audit')->assertOk();
        $this->actingAs(User::factory()->owner()->create())->get('/settings/audit')
            ->assertInertia(fn (Assert $page) => $page->component('settings/audit')->where('can.view_audit_log', true));
    }

    public function test_the_audit_log_shows_newest_first_and_names_the_subject(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Olivia']);
        $application = Application::factory()->create(['name' => 'shop']);
        AuditEvent::record($owner, 'application.created', $application);
        AuditEvent::record(null, 'system.cleanup', null);
        AuditEvent::record($owner, 'application.deleted', $application);
        $application->delete();

        $this->actingAs($owner)->get('/settings/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 3)
                ->where('events.data.0.action', 'application.deleted')
                ->where('events.data.0.user', 'Olivia')
                ->where('events.data.0.subject', 'application #'.$application->id.' (deleted)')
                ->where('events.data.1.user', null)
                ->where('events.data.1.subject', null)
                ->where('events.data.2.subject', 'application #'.$application->id.' (deleted)'));
    }

    public function test_the_audit_log_is_paginated(): void
    {
        $owner = User::factory()->owner()->create();
        AuditEvent::factory()->count(30)->create();

        $this->actingAs($owner)->get('/settings/audit')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 25)->where('events.total', 30)->where('events.last_page', 2));
        $this->actingAs($owner)->get('/settings/audit?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 5));
    }
}
