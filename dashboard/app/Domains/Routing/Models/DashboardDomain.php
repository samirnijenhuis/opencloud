<?php

namespace App\Domains\Routing\Models;

use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Routing\Enums\DnsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\URL;

/**
 * The hostname of the dashboard. The table holds at most one row.
 */
class DashboardDomain extends Model
{
    public const PORT = 8000;

    protected $fillable = ['hostname', 'dns_status', 'dns_found', 'dns_checked_at'];

    protected $attributes = [
        'dns_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'dns_status' => DnsStatus::class,
            'dns_found' => 'array',
            'dns_checked_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    /** The domain, only after its DNS check passed. */
    public static function verified(): ?self
    {
        $domain = self::current();

        return $domain?->dns_status->routable() ? $domain : null;
    }

    public static function localCluster(): ?Cluster
    {
        return Cluster::query()->where('kind', ClusterKind::Local)->first();
    }

    /** The address people open the dashboard at. */
    public static function address(): string
    {
        $verified = self::verified();

        if ($verified !== null) {
            return "https://{$verified->hostname}";
        }

        $ip = self::localCluster()?->ingress_ip;

        return $ip === null ? config('app.url') : 'http://'.$ip.':'.self::PORT;
    }

    /**
     * Makes every generated link use the verified domain.
     * Before the migrations ran, the table does not exist and the APP_URL stays.
     */
    public static function applyRootUrl(): void
    {
        try {
            $verified = self::verified();
        } catch (QueryException) {
            return;
        }

        if ($verified === null) {
            return;
        }

        $url = "https://{$verified->hostname}";
        config(['app.url' => $url]);
        URL::forceRootUrl($url);
        URL::forceScheme('https');
    }
}
