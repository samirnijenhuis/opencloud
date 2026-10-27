<?php

namespace App\Domains\Clusters\Models;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\ClusterRegistry;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Datastores\Models\Datastore;
use Database\Factories\ClusterFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ClusterFactory::class)]
class Cluster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'kind', 'provider', 'kubeconfig', 'ingress_ip', 'parts_version', 'status', 'error',
        'registry_url', 'registry_username', 'registry_password', 'registry_insecure',
        'region', 'node_size', 'min_nodes', 'max_nodes', 'provider_cluster_id', 'provider_vpc_id', 'tofu_log',
    ];

    protected $hidden = ['kubeconfig', 'registry_password', 'tofu_log'];

    protected $attributes = [
        'status' => 'pending',
        'registry_insecure' => false,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ClusterKind::class,
            'status' => ClusterStatus::class,
            'kubeconfig' => 'encrypted',
            'registry_password' => 'encrypted',
            'registry_insecure' => 'boolean',
            'min_nodes' => 'integer',
            'max_nodes' => 'integer',
        ];
    }

    /** Null means the cluster pulls from the local registry, which only the local cluster can reach. */
    public function registry(): ?ClusterRegistry
    {
        if ($this->registry_url === null) {
            return null;
        }

        return new ClusterRegistry($this->registry_url, (string) $this->registry_username, (string) $this->registry_password, $this->registry_insecure);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<Datastore, $this> */
    public function datastores(): HasMany
    {
        return $this->hasMany(Datastore::class);
    }
}
