<?php

namespace App\Models;

use App\Enums\ClusterKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'kind', 'provider', 'kubeconfig', 'ingress_ip', 'parts_version', 'status'];

    protected $hidden = ['kubeconfig'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ClusterKind::class,
            'kubeconfig' => 'encrypted',
        ];
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
