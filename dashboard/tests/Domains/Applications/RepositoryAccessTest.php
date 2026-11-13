<?php

namespace Tests\Domains\Applications;

use App\Domains\Applications\Enums\PreviewDelivery;
use App\Domains\Applications\Enums\PushDelivery;
use App\Domains\Applications\Enums\RepositoryRead;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\RepositoryAccess;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\Enums\AppReach;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryAccessTest extends TestCase
{
    /** @return array<string, array{0: EnvironmentKind, 1: bool, 2: bool, 3: AppReach, 4: ?PushDelivery}> */
    public static function pushes(): array
    {
        return [
            'the app delivers a push' => [EnvironmentKind::PERMANENT, true, false, AppReach::INSTALLED, PushDelivery::GITHUB_APP],
            'the app wins over the deploy hook' => [EnvironmentKind::PERMANENT, true, true, AppReach::INSTALLED, PushDelivery::GITHUB_APP],
            'the deploy hook without the app' => [EnvironmentKind::PERMANENT, true, true, AppReach::NOT_INSTALLED, PushDelivery::DEPLOY_HOOK],
            'the deploy hook with auto deploy off' => [EnvironmentKind::PERMANENT, false, true, AppReach::INSTALLED, PushDelivery::DEPLOY_HOOK],
            'auto deploy without the app' => [EnvironmentKind::PERMANENT, true, false, AppReach::NOT_INSTALLED, PushDelivery::NOT_CONNECTED],
            'auto deploy with an unknown reach' => [EnvironmentKind::PERMANENT, true, false, AppReach::UNKNOWN, PushDelivery::NOT_CONNECTED],
            'deploys by hand' => [EnvironmentKind::PERMANENT, false, false, AppReach::INSTALLED, PushDelivery::OFF],
            'a preview' => [EnvironmentKind::PREVIEW, true, false, AppReach::INSTALLED, null],
            'the preview template' => [EnvironmentKind::PREVIEW_TEMPLATE, true, true, AppReach::INSTALLED, null],
        ];
    }

    #[DataProvider('pushes')]
    public function test_the_push_delivery_follows_auto_deploy_the_deploy_hook_and_the_app(EnvironmentKind $kind, bool $autoDeploy, bool $deployHook, AppReach $app, ?PushDelivery $push): void
    {
        $environment = new Environment(['kind' => $kind, 'auto_deploy' => $autoDeploy, 'deploy_hook_enabled' => $deployHook]);

        $this->assertSame($push, self::access(RepositoryRead::PUBLIC, app: $app)->push($environment));
    }

    /** @return array<string, array{0: bool, 1: AppReach, 2: PreviewDelivery}> */
    public static function previews(): array
    {
        return [
            'previews off' => [false, AppReach::INSTALLED, PreviewDelivery::OFF],
            'the app delivers pull requests' => [true, AppReach::INSTALLED, PreviewDelivery::ON],
            'the app is not installed' => [true, AppReach::NOT_INSTALLED, PreviewDelivery::NOT_CONNECTED],
            'the app belongs to another account' => [true, AppReach::OTHER_ACCOUNT, PreviewDelivery::NOT_CONNECTED],
        ];
    }

    #[DataProvider('previews')]
    public function test_the_preview_delivery_follows_the_previews_switch_and_the_app(bool $enabled, AppReach $app, PreviewDelivery $previews): void
    {
        $this->assertSame($previews, self::access(RepositoryRead::PUBLIC, app: $app)->previews(new Application(['previews_enabled' => $enabled])));
    }

    /** @return array<string, array{0: RepositoryAccess, 1: string}> */
    public static function summaries(): array
    {
        $gitlab = 'https://gitlab.com/acme/shop.git';

        return [
            'the app' => [self::access(RepositoryRead::GITHUB_APP), 'The GitHub App reads this repository.'],
            'a deploy key' => [self::access(RepositoryRead::DEPLOY_KEY), 'The deploy key reads this repository.'],
            'public on github.com' => [self::access(RepositoryRead::PUBLIC), 'Public repository. Opencloud can read it.'],
            'public on another host' => [self::access(RepositoryRead::PUBLIC, $gitlab), 'Public repository on gitlab.com. Opencloud can read it.'],
            'a missing branch' => [self::access(RepositoryRead::BRANCH_MISSING, branch: 'develop', defaultBranch: 'main'), 'The branch develop does not exist in acme/shop. The default branch is main.'],
            'a missing branch in an empty repository' => [self::access(RepositoryRead::BRANCH_MISSING, branch: 'develop'), 'The branch develop does not exist in acme/shop.'],
            'a refused key' => [self::access(RepositoryRead::KEY_REFUSED), 'GitHub does not accept the deploy key yet. Add the public key to the repository under Settings, Deploy keys.'],
            'unreadable on github.com' => [self::access(RepositoryRead::UNREADABLE), 'Opencloud cannot read acme/shop. It is private, or the name is wrong.'],
            'unreadable on another host' => [self::access(RepositoryRead::UNREADABLE, $gitlab), 'Opencloud cannot read this repository. Outside github.com, opencloud reads only a public repository.'],
        ];
    }

    #[DataProvider('summaries')]
    public function test_each_read_state_has_one_sentence(RepositoryAccess $access, string $summary): void
    {
        $this->assertSame($summary, $access->summary());
    }

    private static function access(RepositoryRead $read, string $repository = 'https://github.com/acme/shop.git', ?string $branch = null, ?string $defaultBranch = null, AppReach $app = AppReach::NO_APP): RepositoryAccess
    {
        $githubRepository = str_starts_with($repository, 'https://github.com/') ? 'acme/shop' : null;

        return new RepositoryAccess($repository, $githubRepository, $branch, $read, $defaultBranch, $app, CarbonImmutable::parse('2026-10-31 12:00:00'));
    }
}
