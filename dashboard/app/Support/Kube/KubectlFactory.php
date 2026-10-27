<?php

namespace App\Support\Kube;

use App\Domains\Clusters\Models\Cluster;

class KubectlFactory
{
    public function for(Cluster $cluster): Kubectl
    {
        return new Kubectl($cluster);
    }
}
