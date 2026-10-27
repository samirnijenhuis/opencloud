<?php

namespace App\Domains\Datastores\Models;

use App\Domains\Backups\Models\Backup;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use Database\Factories\DatastoreFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(DatastoreFactory::class)]
class Datastore extends Model
{
    use HasFactory;

    protected $fillable = ['cluster_id', 'kind', 'source', 'version', 'status', 'root_password', 'error', 'tofu_log'];

    protected $hidden = ['root_password', 'tofu_log'];

    protected $attributes = [
        'source' => DatastoreSource::Standard->value,
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DatastoreKind::class,
            'source' => DatastoreSource::class,
            'status' => DatastoreStatus::class,
            'root_password' => 'encrypted',
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
