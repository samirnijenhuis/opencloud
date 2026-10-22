<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Cluster;
use App\Models\User;

class ClusterPolicy
{
    public function view(User $user, Cluster $cluster): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }

    /**
     * A kubeconfig gives full access to every secret on the cluster.
     */
    public function update(User $user, Cluster $cluster): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }

    public function delete(User $user, Cluster $cluster): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }
}
