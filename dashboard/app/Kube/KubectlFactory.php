<?php

namespace App\Kube;

use App\Models\Cluster;

class KubectlFactory
{
    public function for(Cluster $cluster): Kubectl
    {
        return new Kubectl($cluster);
    }
}
