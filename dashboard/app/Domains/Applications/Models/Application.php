<?php

namespace App\Domains\Applications\Models;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Policies\ApplicationPolicy;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\RepositoryName;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ApplicationFactory::class)]
#[UsePolicy(ApplicationPolicy::class)]
class Application extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'repository', 'cluster_id', 'type', 'port', 'previews_enabled', 'preview_domain', 'preview_labels', 'deploy_private_key', 'deploy_public_key'];

    protected $hidden = ['deploy_private_key'];

    protected $attributes = [
        'port' => 8080,
        'previews_enabled' => true,
        'preview_labels' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApplicationType::class,
            'port' => 'integer',
            'previews_enabled' => 'boolean',
            'preview_labels' => 'array',
            'deploy_private_key' => 'encrypted',
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

    /**
     * One repository can back more than one application, for example a staging copy.
     *
     * @param  string  $repository  "owner/name" from RepositoryName::parse()
     * @return Collection<int, self>
     */
    public static function forGitHubRepository(string $repository): Collection
    {
        return self::query()->orderBy('id')->get()
            ->filter(fn (self $application) => RepositoryName::parse($application->repository) === $repository)
            ->values();
    }

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }
}
