<?php

namespace App\Domains\Previews;

use App\Domains\Applications\Models\Application;
use App\Domains\Environments\Enums\EnvironmentKind;

/**
 * The rules that decide whether a pull request gets a preview. SyncPreview, OpenPreview, DeployPullRequest, and the
 * Pull requests page of the preview template share them.
 */
final class PreviewRules
{
    /**
     * GitHub compares label names without case, so this does too. An empty filter holds no label.
     *
     * @param  list<string>  $labels
     */
    public static function hasFilterLabel(Application $application, array $labels): bool
    {
        return array_intersect(array_map(mb_strtolower(...), $application->preview_labels), array_map(mb_strtolower(...), $labels)) !== [];
    }

    /**
     * Without a filter, each pull request passes. With labels in the filter, a pull request passes only while it has one of them.
     *
     * @param  list<string>  $labels
     */
    public static function passesLabelFilter(Application $application, array $labels): bool
    {
        return $application->preview_labels === [] || self::hasFilterLabel($application, $labels);
    }

    /** True when a new preview would pass the limit of the application. 0 means no limit. */
    public static function atLimit(Application $application): bool
    {
        if ($application->max_previews === 0) {
            return false;
        }

        return $application->environments()->where('kind', EnvironmentKind::PREVIEW)->count() >= $application->max_previews;
    }

    /**
     * The reason that a pull request cannot get a preview, or null when it can.
     *
     * @param  list<string>  $labels
     */
    public static function refusal(Application $application, bool $fromFork, array $labels): ?string
    {
        if (! $application->previews_enabled) {
            return 'Turn on previews first.';
        }

        if ($fromFork) {
            return 'Opencloud does not preview a pull request from a fork.';
        }

        if (! self::passesLabelFilter($application, $labels)) {
            return 'It needs one of the labels: '.implode(', ', $application->preview_labels).'.';
        }

        return null;
    }
}
