<?php

namespace App\Models;

use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Datastore extends Model
{
    use HasFactory;

    protected $fillable = ['cluster_id', 'kind', 'source', 'version', 'status'];

    protected $attributes = [
        'source' => DatastoreSource::Standard->value,
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DatastoreKind::class,
            'source' => DatastoreSource::class,
        ];
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return HasMany<DatastoreAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(DatastoreAttachment::class);
    }

    /** @return HasMany<Backup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
