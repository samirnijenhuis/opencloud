<?php

namespace App\Domains\Environments\Models;

use App\Domains\Environments\Enums\ToolKind;
use Database\Factories\ToolFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dev tool of a preview, on a hostname of its own. On the preview template, the hostname is a pattern with
 * {pr_number} in the first label, and each new preview gets a copy with its number.
 */
#[UseFactory(ToolFactory::class)]
class Tool extends Model
{
    use HasFactory;

    protected $fillable = ['kind', 'hostname'];

    protected function casts(): array
    {
        return [
            'kind' => ToolKind::class,
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
