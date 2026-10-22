<?php

namespace App\Models;

use App\Clusters\ClusterRegistry;
use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'kind', 'provider', 'kubeconfig', 'ingress_ip', 'parts_version', 'status', 'error',
        'registry_url', 'registry_username', 'registry_password', 'registry_insecure',
    ];

    protected $hidden = ['kubeconfig', 'registry_password'];

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
