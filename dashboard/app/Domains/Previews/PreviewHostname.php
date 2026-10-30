<?php

namespace App\Domains\Previews;

use App\Domains\Applications\Models\Application;
use Illuminate\Support\Str;

class PreviewHostname
{
    public const PR_NUMBER = '{pr_number}';

    /**
     * With a pattern of the owner, the owner points one wildcard record at the cluster. Without one, sslip.io resolves the IP in the name.
     * The pattern is null when the application has no pattern and the cluster has no ingress IP.
     */
    public function pattern(Application $application): ?string
    {
        if ($application->preview_domain !== null) {
            return $application->preview_domain;
        }

        $ip = $application->cluster->ingress_ip;

        // A DNS label holds at most 63 characters.
        $slug = rtrim(substr(Str::slug($application->name), 0, 50), '-');

        return $ip === null ? null : 'pr-'.self::PR_NUMBER."-{$slug}.{$ip}.sslip.io";
    }

    public function for(Application $application, int $number): ?string
    {
        $pattern = $this->pattern($application);

        return $pattern === null ? null : str_replace(self::PR_NUMBER, (string) $number, $pattern);
    }
}
