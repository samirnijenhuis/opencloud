<?php

namespace App\Domains\Clusters\Policies;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Models\User;

class ClusterPolicy
{
    public function view(User $user, Cluster $cluster): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }

    /**
     * A kubeconfig gives full access to every secret on the cluster.
     */
    public function update(User $user, Cluster $cluster): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }

    public function delete(User $user, Cluster $cluster): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }
}
