<?php

namespace App\Domains\Environments\Models;

use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use Closure;
use Cron\CronExpression;
use Database\Factories\ProcessFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One command of an environment next to web. A long-running process, for example a queue worker, runs as its own
 * Kubernetes Deployment. A scheduled process, for example the Laravel scheduler, runs as a CronJob.
 */
#[UseFactory(ProcessFactory::class)]
class Process extends Model
{
    use HasFactory;

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

    /** The columns that only one kind uses. The other kind stores null in them. */
    private const KIND_FIELDS = ['schedule', 'min_instances', 'max_instances', 'cpu_target'];

    protected $fillable = ['name', 'command', 'kind', 'schedule', 'size', 'min_instances', 'max_instances', 'cpu_target'];

    protected function casts(): array
    {
        return [
            'kind' => ProcessKind::class,
            'size' => Size::class,
            'min_instances' => 'integer',
            'max_instances' => 'integer',
            'cpu_target' => 'integer',
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * The rules for what a process runs, with each key under $prefix. Only a scheduled process has a schedule.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'regex:'.self::NAME, Rule::notIn(self::RESERVED)],
            "{$prefix}kind" => ['required', Rule::enum(ProcessKind::class)],
            "{$prefix}command" => ['required', 'string', 'max:1000'],
            "{$prefix}schedule" => [self::onlyFor(ProcessKind::SCHEDULED, $prefix), 'required', 'string', 'max:100', self::cron(...)],
        ];
    }

    /**
     * The rules for the size and the instances. A scheduled process starts one pod for each run, so it has no instances.
     *
     * @return array<string, list<mixed>>
     */
    public static function computeRules(string $prefix = ''): array
    {
        $longRunning = self::onlyFor(ProcessKind::LONG_RUNNING, $prefix);

        return [
            "{$prefix}size" => ['required', Rule::enum(Size::class)],
            "{$prefix}min_instances" => [$longRunning, 'required', 'integer', 'min:1', 'max:'.self::MAX_INSTANCES],
            "{$prefix}max_instances" => [$longRunning, 'required', 'integer', 'min:1', 'max:'.self::MAX_INSTANCES, "gte:{$prefix}min_instances"],
            "{$prefix}cpu_target" => [$longRunning, 'required', 'integer', 'min:10', 'max:95'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        $instances = 'Use 1 to '.self::MAX_INSTANCES.' instances.';

        return [
            "{$prefix}name.required" => 'Enter a name for the process.',
            "{$prefix}name.regex" => 'Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.',
            "{$prefix}name.not_in" => 'Opencloud uses the names web, deploy, and command. Use a different name.',
            "{$prefix}kind.required" => 'Choose long-running or scheduled.',
            "{$prefix}kind.enum" => 'Choose long-running or scheduled.',
            "{$prefix}command.required" => 'Enter the command that starts the process.',
            "{$prefix}command.max" => 'Use a command of 1000 characters or fewer.',
            "{$prefix}schedule.required" => 'Enter a schedule, for example * * * * * for each minute.',
            "{$prefix}schedule.max" => 'Use a schedule of 100 characters or fewer.',
            "{$prefix}size.required" => 'Choose a size.',
            "{$prefix}size.enum" => 'Choose a size.',
            "{$prefix}min_instances.required" => $instances,
            "{$prefix}min_instances.min" => $instances,
            "{$prefix}min_instances.max" => $instances,
            "{$prefix}max_instances.required" => $instances,
            "{$prefix}max_instances.min" => $instances,
            "{$prefix}max_instances.max" => $instances,
            "{$prefix}max_instances.gte" => 'Use a maximum that is the same as the minimum or more.',
            "{$prefix}cpu_target.required" => 'Use a CPU target from 10 to 95 percent.',
            "{$prefix}cpu_target.min" => 'Use a CPU target from 10 to 95 percent.',
            "{$prefix}cpu_target.max" => 'Use a CPU target from 10 to 95 percent.',
        ];
    }

    /**
     * Trims the values and checks them against the rules and the other processes of the environment.
     * The values that the kind does not use become null, so a scheduled process keeps no instances.
     *
     * @return array{name: string, kind: string, command: string, schedule: ?string, size: string, min_instances: ?int, max_instances: ?int, cpu_target: ?int}
     *
     * @throws ValidationException when a value breaks a rule or another process has the name
     */
    public static function validate(
        Environment $environment,
        string $name,
        string $command,
        ProcessKind $kind,
        ?string $schedule,
        Size $size,
        ?int $minInstances,
        ?int $maxInstances,
        ?int $cpuTarget,
        ?self $ignore = null,
    ): array {
        $attributes = [
            'name' => trim($name),
            'kind' => $kind->value,
            'command' => trim($command),
            'schedule' => $schedule === null ? null : trim($schedule),
            'size' => $size->value,
            'min_instances' => $minInstances,
            'max_instances' => $maxInstances,
            'cpu_target' => $cpuTarget,
        ];
        $rules = [...self::rules(), ...self::computeRules()];
        $rules['name'][] = Rule::unique('processes')->where('environment_id', $environment->id)->ignore($ignore);

        $validated = Validator::make($attributes, $rules, [
            ...self::messages(),
            'name.unique' => "This environment already has a process with the name {$attributes['name']}.",
        ])->validate();

        return $validated + array_fill_keys(self::KIND_FIELDS, null);
    }

    /** The rule that drops a value from the validated data unless the process has this kind. */
    private static function onlyFor(ProcessKind $kind, string $prefix): string
    {
        return "exclude_unless:{$prefix}kind,{$kind->value}";
    }

    /**
     * Kubernetes reads the schedule with robfig/cron. That parser has no L or W in the day of the month and no L or #
     * in the day of the week, so Kubernetes refuses such a CronJob, although CronExpression accepts it.
     */
    private static function cron(string $attribute, mixed $value, Closure $fail): void
    {
        $fields = preg_split('/\s+/', trim((string) $value)) ?: [];
        $quartz = count($fields) === 5 && (preg_match('/[LW]/i', $fields[2]) === 1 || preg_match('/[L#]/i', $fields[4]) === 1);

        if (! CronExpression::isValidExpression((string) $value) || $quartz) {
            $fail('Enter a cron schedule with five fields, for example */5 * * * * for each five minutes. Kubernetes does not accept L, W, or #.');
        }
    }
}
