<?php

namespace App\Models;

use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvVar extends Model
{
    use HasFactory;

    protected $fillable = ['environment_id', 'key', 'value', 'is_secret', 'available_at', 'source'];

    protected $hidden = ['value'];

    protected $attributes = [
        'is_secret' => false,
        'available_at' => EnvVarAvailability::Runtime->value,
        'source' => EnvVarSource::User->value,
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
            'available_at' => EnvVarAvailability::class,
            'source' => EnvVarSource::class,
        ];
    }

    /**
     * Nobody reads a saved secret in the dashboard, so a secret serializes without its value.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'value' => $this->is_secret ? null : $this->value,
            'has_value' => ($this->getAttributes()['value'] ?? null) !== null,
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
