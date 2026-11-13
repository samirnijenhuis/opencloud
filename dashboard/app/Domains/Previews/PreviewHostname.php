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

    /**
     * The pattern of a hostname next to the app of a preview: the prefix takes the place of the text before the number in
     * the first label, so the wildcard record of the previews covers it too. pr-{pr_number}.previews.example.com and the
     * prefix mail give mail-{pr_number}.previews.example.com.
     */
    public function sidePattern(Application $application, string $prefix): ?string
    {
        $pattern = $this->pattern($application);

        return $pattern === null ? null : $prefix.'-'.strstr($pattern, self::PR_NUMBER);
    }

    /** Null without a pattern, and when the first label is longer than the 63 characters that DNS allows. */
    public function forSide(Application $application, int $number, string $prefix): ?string
    {
        $pattern = $this->sidePattern($application, $prefix);
        $hostname = $pattern === null ? null : str_replace(self::PR_NUMBER, (string) $number, $pattern);

        return $hostname === null || strlen(strstr($hostname, '.', true)) > 63 ? null : $hostname;
    }
}
