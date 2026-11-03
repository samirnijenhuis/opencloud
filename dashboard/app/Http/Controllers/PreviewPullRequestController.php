<?php

namespace App\Http\Controllers;

use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\RepositoryName;
use App\Domains\Previews\Actions\DeployPullRequest;
use App\Domains\Previews\PreviewRules;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\DeployPullRequestRequest;
use App\Http\Requests\ShowPullRequestsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/** The Pull requests tab of the preview template: the open pull requests, their previews, and a deploy for each one. */
class PreviewPullRequestController extends Controller
{
    use PresentsEnvironment;

    public function index(ShowPullRequestsRequest $request, Environment $environment, GitHubClient $github): Response
    {
        $application = $environment->application;
        $repository = RepositoryName::parse($application->repository);
        $pullRequests = $repository === null ? null : $github->openPullRequests($repository);
        $previews = $application->environments()->where('kind', EnvironmentKind::PREVIEW)->get()->keyBy('pr_number');
        $latest = Deployment::query()
            ->whereIn('id', Deployment::query()->selectRaw('max(id)')->whereIn('environment_id', $previews->modelKeys())->groupBy('environment_id'))
            ->get()
            ->keyBy('environment_id');

        return Inertia::render('environments/pull-requests', [
            ...$this->environmentProps($environment, $request->user()),
            'repository' => $repository ?? $application->repository,
            // Null when the GitHub App cannot read the pull requests of the repository.
            'pull_requests' => $pullRequests === null ? null : array_map(fn (array $pullRequest) => [
                ...Arr::only($pullRequest, ['number', 'title', 'author', 'head_ref', 'labels', 'url']),
                'preview' => ($preview = $previews->get($pullRequest['number'])) === null ? null : [
                    'id' => $preview->id,
                    'name' => $preview->name,
                    'status' => $latest->get($preview->id)?->status,
                ],
                'refusal' => PreviewRules::refusal($application, $pullRequest['from_fork'], $pullRequest['labels']),
            ], $pullRequests),
        ]);
    }

    public function deploy(DeployPullRequestRequest $request, Environment $environment, int $number, DeployPullRequest $deployPullRequest): RedirectResponse
    {
        $deployPullRequest->execute($environment->application, $request->user(), $number);

        return back();
    }
}
