<?php

namespace App\Domains\Deployments;

use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Enums\DeploymentStep;
use App\Domains\Deployments\Models\Deployment;
use Illuminate\Support\Carbon;

/**
 * Reads the log of a deployment as a list of steps. The marker lines that `DeploymentLog::step()` writes split the log.
 */
final class DeploymentSteps
{
    /**
     * A log without markers gives one step with no key. Each deployment from before the markers has such a log.
     *
     * @return list<array{key: ?string, title: string, phase: ?string, status: 'finished'|'running'|'failed'|'skipped', started_at: ?string, finished_at: ?string, lines: list<array{offset: int, text: string}>}>
     */
    public static function of(Deployment $deployment): array
    {
        $log = (string) $deployment->log;

        if ($log === '') {
            return [];
        }

        $groups = self::group($log);
        $zero = $deployment->started_at ?? Carbon::parse($groups[0]['at']);
        $ended = $deployment->status->isRunning() ? null : $deployment->finished_at;
        $last = count($groups) - 1;
        $steps = [];

        foreach ($groups as $index => $group) {
            $step = $group['step'];
            $steps[] = [
                'key' => $step?->value,
                'title' => $step?->title() ?? 'Log',
                'phase' => $step?->phase(),
                'status' => $index < $last ? 'finished' : match ($deployment->status) {
                    DeploymentStatus::FAILED => 'failed',
                    DeploymentStatus::LIVE, DeploymentStatus::SUPERSEDED => 'finished',
                    default => 'running',
                },
                'started_at' => $group['at'],
                'finished_at' => $index < $last ? $groups[$index + 1]['at'] : $ended?->toIso8601ZuluString(),
                'lines' => array_map(fn (array $line) => [
                    'offset' => (int) Carbon::parse($line['at'])->diffInSeconds($zero, true),
                    'text' => $line['text'],
                ], $group['lines']),
            ];
        }

        if (! $deployment->status->isRunning() && $groups[$last]['step'] !== null) {
            foreach (array_slice(DeploymentStep::cases(), array_search($groups[$last]['step'], DeploymentStep::cases(), true) + 1) as $step) {
                $steps[] = ['key' => $step->value, 'title' => $step->title(), 'phase' => $step->phase(), 'status' => 'skipped', 'started_at' => null, 'finished_at' => null, 'lines' => []];
            }
        }

        return $steps;
    }

    /**
     * Lines before the first marker join the first step.
     *
     * @return non-empty-list<array{step: ?DeploymentStep, at: string, lines: list<array{at: string, text: string}>}>
     */
    private static function group(string $log): array
    {
        $groups = [];

        foreach (explode("\n", rtrim($log, "\n")) as $row) {
            [$at, $text] = array_pad(explode(' ', $row, 2), 2, '');
            $step = str_starts_with($text, DeploymentLog::MARKER) ? DeploymentStep::tryFrom(substr($text, 1)) : null;

            if ($step !== null) {
                $groups[] = ['step' => $step, 'at' => $at, 'lines' => []];
            } elseif ($groups === []) {
                $groups[] = ['step' => null, 'at' => $at, 'lines' => [['at' => $at, 'text' => $text]]];
            } else {
                $groups[array_key_last($groups)]['lines'][] = ['at' => $at, 'text' => $text];
            }
        }

        return self::adoptLeadingLines($groups);
    }

    /**
     * @param  non-empty-list<array{step: ?DeploymentStep, at: string, lines: list<array{at: string, text: string}>}>  $groups
     * @return non-empty-list<array{step: ?DeploymentStep, at: string, lines: list<array{at: string, text: string}>}>
     */
    private static function adoptLeadingLines(array $groups): array
    {
        if ($groups[0]['step'] !== null || count($groups) === 1) {
            return $groups;
        }

        $leading = array_shift($groups);
        $groups[0]['lines'] = [...$leading['lines'], ...$groups[0]['lines']];

        return $groups;
    }
}
