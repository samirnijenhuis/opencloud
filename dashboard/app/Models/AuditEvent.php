<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

class AuditEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    private const SECRET_KEYS = ['value', 'secret', 'password'];

    protected $fillable = ['action', 'context'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidArgumentException when the context has a key that can hold a secret value
     */
    public static function record(?User $user, string $action, ?Model $subject, array $context = []): self
    {
        self::refuseSecretKeys($context);

        $event = new self(['action' => $action, 'context' => $context]);
        $event->user()->associate($user);
        $event->subject()->associate($subject);
        $event->save();

        return $event;
    }

    /** @param  array<array-key, mixed>  $context */
    private static function refuseSecretKeys(array $context): void
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::SECRET_KEYS, true)) {
                throw new InvalidArgumentException("An audit event cannot store the context key [{$key}].");
            }

            if (is_array($value)) {
                self::refuseSecretKeys($value);
            }
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
