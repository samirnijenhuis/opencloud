<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Deployment extends Model
{
    use HasFactory;

    protected $fillable = ['environment_id', 'commit_sha', 'image', 'status', 'triggered_by', 'started_at', 'finished_at', 'warning'];

    protected $attributes = [
        'status' => DeploymentStatus::Queued->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @throws LogicException when the state machine does not allow the move */
    public function moveTo(DeploymentStatus $next): void
    {
        if (! $this->status->canMoveTo($next)) {
            throw new LogicException("Deployment {$this->id} cannot move from {$this->status->value} to {$next->value}.");
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
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
