<?php

namespace App\Models;

use App\Enums\ApplicationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'repository', 'cluster_id', 'type', 'port', 'previews_enabled', 'allow_forks', 'preview_domain'];

    protected $attributes = [
        'port' => 8080,
        'previews_enabled' => true,
        'allow_forks' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ApplicationType::class,
            'port' => 'integer',
            'previews_enabled' => 'boolean',
            'allow_forks' => 'boolean',
        ];
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** A repository is "owner/name" on GitHub, or a full URL. */
    public function cloneUrl(): string
    {
        return str_contains($this->repository, '://') ? $this->repository : "https://github.com/{$this->repository}.git";
    }

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }
}
