<?php

namespace App\Domains\Datastores\Models;

use App\Domains\Backups\Models\Backup;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use Database\Factories\DatastoreAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(DatastoreAttachmentFactory::class)]
class DatastoreAttachment extends Model
{
    use HasFactory;

    protected $fillable = ['datastore_id', 'environment_id', 'name', 'is_default', 'host', 'port', 'database_name', 'username', 'password', 'tls', 'region', 'path_style', 'status', 'error'];

    protected $hidden = ['password'];

    protected $attributes = [
        'status' => DatastoreStatus::PENDING->value,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'tls' => 'boolean',
            'path_style' => 'boolean',
            'port' => 'integer',
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
