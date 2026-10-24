<?php

namespace App\Actions;

use App\Enums\Size;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class UpdateEnvironmentSettings
{
    public const FIELDS = [
        'branch',
        'auto_deploy',
        'size',
        'min_instances',
        'max_instances',
        'cpu_target',
        'health_path',
        'build_command',
        'deploy_commands',
    ];

    /**
     * Saves the given settings. The running pods keep the old settings until the next deployment.
     *
     * @param  array<string, mixed>  $settings  any subset of self::FIELDS
     *
     * @throws InvalidArgumentException when the minimum of instances is above the maximum
     */
    public function handle(Environment $environment, User $by, array $settings): Environment
    {
        $settings = Arr::only($settings, self::FIELDS);

        if (isset($settings['size']) && ! $settings['size'] instanceof Size) {
            $settings['size'] = Size::from($settings['size']);
        }

        $environment->fill($settings);

        if ($environment->min_instances > $environment->max_instances) {
            throw new InvalidArgumentException('The minimum of instances cannot be above the maximum.');
        }

        $changes = [];

        foreach (array_keys($settings) as $field) {
            if ($environment->isDirty($field)) {
                $changes[$field] = ['from' => $this->plain($environment->getOriginal($field)), 'to' => $this->plain($environment->getAttribute($field))];
            }
        }

        if ($changes === []) {
            return $environment;
        }

        $environment->save();

        AuditEvent::record($by, 'environment.updated', $environment, ['changes' => $changes]);

        return $environment;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
