<?php

namespace App\Http\Requests;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Process;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('repository'))) {
            $merge['repository'] = self::normalizeRepository($this->input('repository'));
        }

        if (is_string($this->input('domain'))) {
            $merge['domain'] = DomainRequest::normalizeHostname($this->input('domain'));
        }

        if (is_array($this->input('deploy_commands'))) {
            $merge['deploy_commands'] = self::cleanLines($this->input('deploy_commands'));
        }

        if (is_string($this->input('build_command'))) {
            $merge['build_command'] = self::cleanScript($this->input('build_command'));
        }

        $this->merge($merge);
    }

    /** "owner/repo" and the SSH address "git@github.com:owner/repo.git" become a GitHub clone URL. A full https URL stays as it is. */
    public static function normalizeRepository(string $repository): string
    {
        $repository = trim($repository);

        if (preg_match('#^(?:git@github\.com:)?([\w.-]+/[\w.-]+?)(?:\.git)?$#', $repository, $match) === 1) {
            return "https://github.com/{$match[1]}.git";
        }

        return $repository;
    }

    /**
     * The one rule for a command field: a command is a line that is not empty and does not start with "#".
     *
     * @param  array<array-key, mixed>  $lines
     * @return list<mixed>
     */
    public static function cleanLines(array $lines): array
    {
        $lines = array_map(fn ($line) => is_string($line) ? trim($line) : $line, $lines);

        return array_values(array_filter($lines, fn ($line) => $line !== null && $line !== '' && ! (is_string($line) && str_starts_with($line, '#'))));
    }

    /** The build command is one text with a command on each line. It is null when no command is left. */
    public static function cleanScript(string $script): ?string
    {
        $commands = self::cleanLines(preg_split('/\R/', $script));

        return $commands === [] ? null : implode("\n", $commands);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $processes = Process::rules('processes.*.');
        $processes['processes.*.name'][] = 'distinct';

        return [
            'repository' => ['required', 'string', 'max:500', 'regex:#^https://[^\s/]+/\S+$#'],
            'name' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:255', 'regex:/^[\w.\/-]+$/'],
            'deploy_key' => ['boolean'],
            'cluster_id' => ['required', 'integer', 'exists:clusters,id'],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'size' => ['required', Rule::enum(Size::class)],
            'min_instances' => ['required', 'integer', 'min:1', 'max:50'],
            'max_instances' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_instances'],
            'cpu_target' => ['required', 'integer', 'min:10', 'max:95'],
            'build_command' => ['nullable', 'string', 'max:1000'],
            'deploy_commands' => ['array', 'max:20'],
            'deploy_commands.*' => ['string', 'max:1000'],
            'processes' => ['array', 'max:20'],
            'processes.*' => ['array'],
            ...$processes,
            'env' => ['array', 'max:200'],
            'env.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'distinct'],
            'env.*.value' => ['nullable', 'string', 'max:65535'],
            'env.*.is_secret' => ['boolean'],
            'env.*.available_at' => ['nullable', Rule::enum(EnvVarAvailability::class)],
            'domain' => ['nullable', 'string', 'regex:'.self::HOSTNAME, 'unique:domains,hostname'],
            'database' => ['required', Rule::in(['mysql', 'postgres', 'none'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'repository.regex' => 'Use owner/repo, git@github.com:owner/repo.git, or a full https address.',
            'env.*.key.regex' => 'Use letters, digits, and underscores. Do not start with a digit.',
            'domain.regex' => 'Enter a host name like shop.example.com.',
            'domain.unique' => 'This domain is already in use.',
            'processes.*.name.distinct' => 'Two processes have this name. Give each process its own name.',
            ...Process::messages('processes.*.'),
        ];
    }
}
