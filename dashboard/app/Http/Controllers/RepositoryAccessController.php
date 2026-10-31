<?php

namespace App\Http\Controllers;

use App\Domains\Applications\RepositoryAccess;
use App\Domains\Applications\RepositoryProbe;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\Models\GithubApp;
use App\Domains\Members\Enums\Ability;
use App\Http\Requests\CheckRepositoryAccessRequest;
use App\Http\Requests\ShowRepositoryAccessRequest;
use Illuminate\Http\JsonResponse;

/** Both routes only read, so they call no Action and record no audit event. resources/js/types/repository-access.ts holds the shape. */
class RepositoryAccessController extends Controller
{
    public function check(CheckRepositoryAccessRequest $request, RepositoryProbe $probe): JsonResponse
    {
        return response()->json($this->accessJson($probe->access($request->application(), $request->validated('branch'))));
    }

    public function show(ShowRepositoryAccessRequest $request, Environment $environment, RepositoryProbe $probe): JsonResponse
    {
        $application = $environment->application;
        $access = $probe->access($application, $environment->branch);

        return response()->json([
            ...$this->accessJson($access),
            'branch' => $environment->branch,
            'push' => $access->push($environment),
            'previews' => $access->previews($application),
            'deploy_public_key' => $application->deploy_public_key,
            'can_update' => $request->user()->can('update', $application),
            'can_manage_credentials' => $request->user()->role->allows(Ability::MANAGE_CREDENTIALS),
        ]);
    }

    /** @return array<string, mixed> */
    private function accessJson(RepositoryAccess $access): array
    {
        $app = GithubApp::query()->first();

        return [
            'repository' => $access->repository,
            'github_repository' => $access->githubRepository,
            'github_url' => $access->githubRepository === null ? null : "https://github.com/{$access->githubRepository}",
            'read' => $access->read,
            'read_works' => $access->read->works(),
            'summary' => $access->summary(),
            'default_branch' => $access->defaultBranch,
            'app' => $access->app,
            'app_slug' => $app?->slug,
            'app_owner' => $app?->owner,
            'install_url' => $app?->installUrl(),
            'checked_at' => $access->checkedAt->toIso8601String(),
        ];
    }
}
