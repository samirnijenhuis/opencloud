<?php

namespace App\Models;

use App\Enums\EnvironmentKind;
use App\Enums\Size;
use App\Environments\EnvironmentColor;
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
        'color',
        'deploy_hook_enabled',
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
        'pr_comment_id',
    ];

    protected $hidden = ['deploy_hook_token'];

    protected $attributes = [
        'is_protected' => false,
        'deploy_hook_enabled' => false,
        'auto_deploy' => true,
        'size' => Size::S->value,
        'min_instances' => 1,
        'max_instances' => 1,
        'cpu_target' => 70,
        'deploy_commands' => '[]',
        'health_path' => '/up',
    ];

    protected static function booted(): void
    {
        static::creating(function (Environment $environment) {
            $environment->color ??= EnvironmentColor::defaultFor($environment->kind);
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => EnvironmentKind::class,
            'is_protected' => 'boolean',
            'color' => EnvironmentColor::class,
            'deploy_hook_token' => 'encrypted',
            'deploy_hook_enabled' => 'boolean',
            'auto_deploy' => 'boolean',
            'pr_number' => 'integer',
            'pr_comment_id' => 'integer',
            'size' => Size::class,
            'min_instances' => 'integer',
            'max_instances' => 'integer',
            'cpu_target' => 'integer',
            'deploy_commands' => 'array',
        ];
    }

    public function kubeNamespace(): string
    {
        return "oc-env-{$this->id}";
    }

    /** @return list<string> web and the names of the other processes, each one a Kubernetes Deployment */
    public function processNames(): array
    {
        return [Process::WEB, ...$this->processes->pluck('name')];
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

    /** @return HasMany<CommandRun, $this> */
    public function commandRuns(): HasMany
    {
        return $this->hasMany(CommandRun::class);
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

    /** @return HasMany<Process, $this> the processes next to web, in the order they were added */
    public function processes(): HasMany
    {
        return $this->hasMany(Process::class)->orderBy('id');
    }
}
