<?php

namespace App\Domains\Environments\Models;

use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\CommandRunStatus;
use App\Domains\Members\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One run of a one-off command, for example `php artisan migrate:status`, in a Kubernetes Job of an environment. */
class CommandRun extends Model
{
    protected $fillable = ['environment_id', 'user_id', 'deployment_id', 'command', 'status', 'exit_code', 'output', 'started_at', 'finished_at'];

    protected $attributes = [
        'status' => CommandRunStatus::QUEUED->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => CommandRunStatus::class,
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @throws LogicException when the state machine does not allow the move */
    public function moveTo(CommandRunStatus $next): void
    {
        if (! $this->status->canMoveTo($next)) {
            throw new LogicException("Command run {$this->id} cannot move from {$this->status->value} to {$next->value}.");
        }

        $this->status = $next;
        $this->save();
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Deployment, $this> the live deployment whose image and env the command runs with */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
