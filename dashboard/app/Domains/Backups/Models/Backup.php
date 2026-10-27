<?php

namespace App\Domains\Backups\Models;

use App\Domains\Backups\Enums\BackupStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(BackupFactory::class)]
class Backup extends Model
{
    use HasFactory;

    protected $fillable = ['datastore_id', 'datastore_attachment_id', 'database_name', 'disk', 'location', 'size', 'status', 'error'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'status' => BackupStatus::class,
        ];
    }

    /** @return BelongsTo<Datastore, $this> */
    public function datastore(): BelongsTo
    {
        return $this->belongsTo(Datastore::class);
    }

    /** @return BelongsTo<DatastoreAttachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(DatastoreAttachment::class, 'datastore_attachment_id');
    }

    /** The attachment that owns the database now. A detach and a new attach keep the database name. */
    public function currentAttachment(): ?DatastoreAttachment
    {
        return DatastoreAttachment::query()
            ->where('datastore_id', $this->datastore_id)
            ->where('database_name', $this->database_name)
            ->first();
    }
}
