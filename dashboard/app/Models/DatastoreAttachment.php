<?php

namespace App\Models;

use App\Datastores\DatastoreStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DatastoreAttachment extends Model
{
    use HasFactory;

    protected $fillable = ['datastore_id', 'environment_id', 'database_name', 'username', 'password', 'status', 'error'];

    protected $hidden = ['password'];

    protected $attributes = [
        'status' => DatastoreStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'status' => DatastoreStatus::class,
        ];
    }

    /** @return BelongsTo<Datastore, $this> */
    public function datastore(): BelongsTo
    {
        return $this->belongsTo(Datastore::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return HasMany<EnvVar, $this> */
    public function envVars(): HasMany
    {
        return $this->hasMany(EnvVar::class);
    }

    /** @return HasMany<Backup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
