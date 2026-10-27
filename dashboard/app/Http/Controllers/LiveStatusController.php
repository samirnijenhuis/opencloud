<?php

namespace App\Http\Controllers;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\LiveStatus;
use App\Domains\Environments\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LiveStatusController extends Controller
{
    public function environment(Environment $environment, LiveStatus $status): JsonResponse
    {
        Gate::authorize('view', $environment);

        return response()->json($status->environment($environment));
    }

    public function cluster(Cluster $cluster, LiveStatus $status): JsonResponse
    {
        Gate::authorize('view', $cluster);

        return response()->json($status->cluster($cluster));
    }
}
