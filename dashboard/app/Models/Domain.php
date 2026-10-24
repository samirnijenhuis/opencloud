<?php

namespace App\Models;

use App\Domains\DnsStatus;
use App\Enums\DnsMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = ['environment_id', 'hostname', 'dns_status', 'dns_mode', 'certificate_status', 'dns_found', 'dns_checked_at', 'cloudflare_zone_id', 'cloudflare_record_id', 'generated'];

    protected $attributes = [
        'dns_status' => DnsStatus::Pending->value,
        'dns_mode' => DnsMode::Manual->value,
        'certificate_status' => 'pending',
        'generated' => false,
    ];

    protected function casts(): array
    {
        return [
            'dns_status' => DnsStatus::class,
            'dns_mode' => DnsMode::class,
            'dns_found' => 'array',
            'dns_checked_at' => 'datetime',
            'generated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
