<?php

namespace App\Domains\Environments\Models;

use App\Domains\Deployments\Models\Deployment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One long-running command of an environment next to web, for example a queue worker. Each runs as its own Kubernetes Deployment.
 */
class Process extends Model
{
    /** The process that serves HTTP. It is not a row, because the environment holds its settings. */
    public const WEB = 'web';

    /** The Job that runs the deploy commands. */
    public const DEPLOY = 'deploy';

    /** The Jobs of one-off commands carry this process label. */
    public const COMMAND = 'command';

    public const RESERVED = [self::WEB, self::DEPLOY, self::COMMAND];

    public const MAX_INSTANCES = 20;

    /** A name is a Kubernetes container name and label value, so it starts and ends with a letter or a digit. */
    private const NAME = '/^[a-z0-9]([a-z0-9-]{0,28}[a-z0-9])?$/';

    protected $fillable = ['name', 'command', 'instances', 'exclusive'];

    protected function casts(): array
    {
        return [
            'instances' => 'integer',
            'exclusive' => 'boolean',
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * The rules for one process, with each key under $prefix. An exclusive process runs exactly one instance.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $exclusive, string $prefix = ''): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'regex:'.self::NAME, Rule::notIn(self::RESERVED)],
            "{$prefix}command" => ['required', 'string', 'max:1000'],
            "{$prefix}instances" => ['required', 'integer', 'min:1', 'max:'.($exclusive ? 1 : self::MAX_INSTANCES)],
            "{$prefix}exclusive" => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(bool $exclusive, string $prefix = ''): array
    {
        $instances = 'Use 1 to '.self::MAX_INSTANCES.' instances.';

        return [
            "{$prefix}name.required" => 'Enter a name for the process.',
            "{$prefix}name.regex" => 'Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.',
            "{$prefix}name.not_in" => 'Opencloud uses the names web, deploy, and command. Use a different name.',
            "{$prefix}command.required" => 'Enter the command that starts the process.',
            "{$prefix}command.max" => 'Use a command of 1000 characters or fewer.',
            "{$prefix}instances.min" => $instances,
            "{$prefix}instances.max" => $exclusive ? 'An exclusive process runs exactly one instance.' : $instances,
        ];
    }

    /**
     * Trims the values and checks them against the rules and the other processes of the environment.
     *
     * @return array{name: string, command: string, instances: int, exclusive: bool}
     *
     * @throws ValidationException when a value breaks a rule or another process has the name
     */
    public static function validate(Environment $environment, string $name, string $command, int $instances, bool $exclusive, ?self $ignore = null): array
    {
        $attributes = ['name' => trim($name), 'command' => trim($command), 'instances' => $instances, 'exclusive' => $exclusive];
        $rules = self::rules($exclusive);
        $rules['name'][] = Rule::unique('processes')->where('environment_id', $environment->id)->ignore($ignore);

        Validator::make($attributes, $rules, [
            ...self::messages($exclusive),
            'name.unique' => "This environment already has a process with the name {$attributes['name']}.",
        ])->validate();

        return $attributes;
    }
}
