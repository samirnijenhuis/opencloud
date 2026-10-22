<?php

namespace Tests\Feature\Http;

use App\Actions\DeleteBackupTarget;
use App\Actions\StoreBackupTarget;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditEvent;
use App\Models\BackupTarget;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class BackupTargetControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $builtConfigs = [];

    private function fakeS3(?Filesystem $disk = null): void
    {
        $disk ??= Storage::fake('bucket');

        Storage::extend('s3', function ($app, array $config) use ($disk) {
            $this->builtConfigs[] = $config;

            return $disk;
        });
    }

    /** @return array<string, string> */
    private function input(array $overrides = []): array
    {
        return ['endpoint' => 'https://s3.example.com', 'bucket' => 'opencloud-backups', 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'super-secret-key', ...$overrides];
    }

    public function test_only_the_owner_opens_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/settings/backups')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/settings/backups')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get('/settings/backups')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/backups')->where('target', null));
    }

    public function test_the_page_never_contains_the_keys(): void
    {
        BackupTarget::factory()->create(['bucket' => 'opencloud-backups', 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'super-secret-key']);
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get('/settings/backups', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])->assertOk();

        $response->assertJsonPath('props.target', ['endpoint' => 'https://s3.example.com', 'bucket' => 'opencloud-backups', 'region' => 'us-east-1', 'path_style' => true, 'keys_set' => true]);
        $this->assertStringNotContainsString('AKIAEXAMPLE', $response->getContent());
        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
    }

    public function test_a_working_bucket_is_checked_and_saved_and_the_test_object_is_removed(): void
    {
        $disk = Storage::fake('bucket');
        $this->fakeS3($disk);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put('/settings/backups', $this->input())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('s3', $this->builtConfigs[0]['driver']);
        $this->assertSame(['https://s3.example.com', 'opencloud-backups', 'AKIAEXAMPLE', 'super-secret-key'], [$this->builtConfigs[0]['endpoint'], $this->builtConfigs[0]['bucket'], $this->builtConfigs[0]['key'], $this->builtConfigs[0]['secret']]);
        $this->assertSame([], $disk->allFiles());

        $target = BackupTarget::sole();
        $this->assertSame(['https://s3.example.com', 'opencloud-backups', 'AKIAEXAMPLE', 'super-secret-key'], [$target->endpoint, $target->bucket, $target->access_key, $target->secret_key]);

        $event = AuditEvent::sole();
        $this->assertSame('backup_target.saved', $event->action);
        $this->assertSame(['endpoint' => 'https://s3.example.com', 'bucket' => 'opencloud-backups', 'region' => 'us-east-1'], $event->context);
        $this->assertStringNotContainsString('super-secret-key', json_encode($event->context));
    }

    public function test_a_bucket_that_fails_the_check_is_not_saved_and_the_error_has_no_secret(): void
    {
        Storage::extend('s3', fn () => throw new RuntimeException('Access denied with key super-secret-key'));

        $this->actingAs(User::factory()->owner()->create())->put('/settings/backups', $this->input())
            ->assertSessionHasErrors(['bucket' => 'The dashboard could not write to this bucket. Check the endpoint, the bucket, and the keys.']);

        $this->assertSame(0, BackupTarget::count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_saving_again_without_keys_keeps_the_saved_keys(): void
    {
        $this->fakeS3();
        BackupTarget::factory()->create(['access_key' => 'AKIAOLD', 'secret_key' => 'old-secret']);

        $this->actingAs(User::factory()->owner()->create())->put('/settings/backups', $this->input(['bucket' => 'other-bucket', 'access_key' => '', 'secret_key' => '']))->assertSessionHasNoErrors();

        $target = BackupTarget::sole();
        $this->assertSame(['other-bucket', 'AKIAOLD', 'old-secret'], [$target->bucket, $target->access_key, $target->secret_key]);
        $this->assertSame(['AKIAOLD', 'old-secret'], [$this->builtConfigs[0]['key'], $this->builtConfigs[0]['secret']]);
    }

    public function test_the_region_and_the_path_style_reach_the_s3_driver_and_are_saved(): void
    {
        $this->fakeS3();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put('/settings/backups', $this->input(['region' => 'eu-central-1', 'path_style' => false]))->assertSessionHasNoErrors();

        $this->assertSame(['eu-central-1', false], [$this->builtConfigs[0]['region'], $this->builtConfigs[0]['use_path_style_endpoint']]);
        $this->assertSame(['eu-central-1', false], [BackupTarget::sole()->region, BackupTarget::sole()->path_style]);
    }

    public function test_the_first_target_needs_both_keys_and_a_valid_endpoint(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put('/settings/backups', $this->input(['access_key' => '', 'secret_key' => '']))->assertSessionHasErrors(['access_key', 'secret_key']);
        $this->actingAs($owner)->put('/settings/backups', $this->input(['endpoint' => 'not a url']))->assertSessionHasErrors('endpoint');
    }

    public function test_an_admin_cannot_save_or_delete_the_target(): void
    {
        $target = BackupTarget::factory()->create();
        $this->mock(StoreBackupTarget::class)->shouldNotReceive('handle');
        $this->mock(DeleteBackupTarget::class)->shouldNotReceive('handle');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/backups', $this->input())->assertForbidden();
        $this->actingAs($admin)->delete('/settings/backups')->assertForbidden();
        $this->assertNotNull($target->fresh());
    }

    public function test_the_controller_calls_the_actions_with_the_right_arguments(): void
    {
        $owner = User::factory()->owner()->create();
        $target = BackupTarget::factory()->create();

        $this->mock(StoreBackupTarget::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (User $by, string $endpoint, string $bucket, ?string $access, ?string $secret) => $by->is($owner)
                && [$endpoint, $bucket, $access, $secret] === ['https://s3.example.com', 'opencloud-backups', 'AKIAEXAMPLE', 'super-secret-key']);
        $this->mock(DeleteBackupTarget::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (BackupTarget $given, User $by) => $given->is($target) && $by->is($owner));

        $this->actingAs($owner)->put('/settings/backups', $this->input())->assertRedirect();
        $this->actingAs($owner)->delete('/settings/backups')->assertRedirect();
    }

    public function test_delete_removes_the_target_and_audits_it_without_keys(): void
    {
        BackupTarget::factory()->create(['bucket' => 'opencloud-backups', 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'super-secret-key']);

        $this->actingAs(User::factory()->owner()->create())->delete('/settings/backups')->assertRedirect();

        $this->assertSame(0, BackupTarget::count());
        $event = AuditEvent::sole();
        $this->assertSame('backup_target.deleted', $event->action);
        $this->assertStringNotContainsString('super-secret-key', json_encode($event->context));
    }
}
