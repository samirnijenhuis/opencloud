<?php

namespace App\Models;

use App\Enums\EnvironmentKind;
use App\Enums\Size;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'name',
        'kind',
        'is_protected',
        'branch',
        'auto_deploy',
        'pr_number',
        'size',
        'min_instances',
        'max_instances',
        'cpu_target',
        'build_command',
        'deploy_commands',
        'health_path',
        'worker_enabled',
        'scheduler_enabled',
    ];

    protected $attributes = [
        'is_protected' => false,
        'auto_deploy' => true,
        'size' => Size::S->value,
        'min_instances' => 1,
        'max_instances' => 1,
        'cpu_target' => 70,
        'deploy_commands' => '[]',
        'health_path' => '/up',
        'worker_enabled' => true,
        'scheduler_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => EnvironmentKind::class,
            'is_protected' => 'boolean',
            'auto_deploy' => 'boolean',
            'pr_number' => 'integer',
            'size' => Size::class,
            'min_instances' => 'integer',
            'max_instances' => 'integer',
            'cpu_target' => 'integer',
            'deploy_commands' => 'array',
            'worker_enabled' => 'boolean',
            'scheduler_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return HasMany<EnvVar, $this> */
    public function envVars(): HasMany
    {
        return $this->hasMany(EnvVar::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @return HasMany<DatastoreAttachment, $this> */
    public function datastoreAttachments(): HasMany
    {
        return $this->hasMany(DatastoreAttachment::class);
    }
}
