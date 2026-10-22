<?php

namespace App\Models;

use App\Enums\DnsMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = ['environment_id', 'hostname', 'dns_status', 'dns_mode', 'certificate_status'];

    protected $attributes = [
        'dns_status' => 'pending',
        'dns_mode' => DnsMode::Manual->value,
        'certificate_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'dns_mode' => DnsMode::class,
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
