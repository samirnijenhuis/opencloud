<?php

namespace Tests\Feature\Jobs;

use App\Domains\Members\Models\User;
use App\Domains\Upgrades\Jobs\CheckLatestRelease;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckLatestReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.github.com/repos/opencloud-dev/opencloud/releases/latest';

    protected function setUp(): void
    {
        parent::setUp();
        config(['opencloud.version' => 'v1.2.0', 'opencloud.repository' => 'opencloud-dev/opencloud']);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function versions(): array
    {
        return [
            'a newer patch' => ['v1.2.1', 'v1.2.0', true],
            'a newer minor with two digits' => ['v1.10.0', 'v1.9.3', true],
            'the same version' => ['v1.2.0', 'v1.2.0', false],
            'an older version' => ['v1.1.9', 'v1.2.0', false],
            'the release after its candidate' => ['v1.2.0', 'v1.2.0-rc.1', true],
            'a candidate before its release' => ['v1.2.0-rc.1', 'v1.2.0', false],
            'test releases' => ['v0.0.2-e2e', 'v0.0.1-e2e', true],
            'a tag without v' => ['1.3.0', 'v1.2.0', true],
            'a development build' => ['v1.3.0', 'dev', false],
            'not a release' => ['nightly', 'v1.2.0', false],
        ];
    }

    #[DataProvider('versions')]
    public function test_it_compares_release_versions(string $candidate, string $running, bool $newer): void
    {
        $this->assertSame($newer, CheckLatestRelease::isNewer($candidate, $running));
    }

    public function test_the_daily_check_shows_a_newer_release_to_the_owner_only(): void
    {
        Http::fake([self::API => Http::response(['tag_name' => 'v1.3.0'])]);
        $event = $this->scheduledCheck();

        $event->run($this->app);

        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertSame(['version' => 'v1.2.0', 'update' => 'v1.3.0'], $this->sidebar(User::factory()->owner()->create()));
        $this->assertSame(['version' => 'v1.2.0', 'update' => null], $this->sidebar(User::factory()->admin()->create()));
    }

    public function test_the_same_release_shows_no_notice(): void
    {
        Http::fake([self::API => Http::response(['tag_name' => 'v1.2.0'])]);

        CheckLatestRelease::dispatchSync();

        $this->assertSame(['version' => 'v1.2.0', 'update' => null], $this->sidebar(User::factory()->owner()->create()));
    }

    public function test_a_failed_check_is_silent_and_keeps_the_last_known_release(): void
    {
        Http::fakeSequence(self::API)
            ->push(['tag_name' => 'v1.3.0'])
            ->push('rate limit', 403)
            ->pushFailedConnection();
        $owner = User::factory()->owner()->create();

        CheckLatestRelease::dispatchSync();
        CheckLatestRelease::dispatchSync();
        CheckLatestRelease::dispatchSync();

        Http::assertSentCount(3);
        $this->assertSame(['version' => 'v1.2.0', 'update' => 'v1.3.0'], $this->sidebar($owner));
    }

    public function test_a_guest_gets_no_version(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('opencloud', null));
    }

    public function test_a_failed_first_check_shows_no_notice(): void
    {
        Http::fake([self::API => Http::response('', 500)]);

        CheckLatestRelease::dispatchSync();

        $this->assertSame(['version' => 'v1.2.0', 'update' => null], $this->sidebar(User::factory()->owner()->create()));
    }

    private function scheduledCheck(): Event
    {
        return collect(app(Schedule::class)->events())->sole(fn (Event $event) => $event->description === CheckLatestRelease::class);
    }

    /**
     * The version line and the update notice that the sidebar of any page shows to the user.
     *
     * @return array{version: string, update: ?string}
     */
    private function sidebar(User $user): array
    {
        $props = [];
        $this->actingAs($user)->get('/applications')->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props']['opencloud'];
        });

        return $props;
    }
}
