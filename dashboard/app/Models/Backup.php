<?php

namespace App\Models;

use App\Backups\BackupStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
