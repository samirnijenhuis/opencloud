<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use Inertia\Inertia;
use Inertia\Response;

class ClusterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('clusters/index', [
            'clusters' => Cluster::query()->orderBy('id')->get(['id', 'name', 'kind', 'ingress_ip', 'status', 'parts_version']),
        ]);
    }
}
