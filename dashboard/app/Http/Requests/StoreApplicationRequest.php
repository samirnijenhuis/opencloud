<?php

namespace App\Http\Requests;

use App\Enums\ApplicationType;
use App\Enums\EnvVarAvailability;
use App\Enums\Size;
use App\Models\Application;
use App\Models\Process;
use App\Presets\EnvProfileKind;
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

        $this->merge($merge);
    }

    /** "owner/repo" becomes a GitHub clone URL. A full https URL stays as it is. */
    public static function normalizeRepository(string $repository): string
    {
        $repository = trim($repository);

        if (preg_match('#^[\w.-]+/[\w.-]+$#', $repository) === 1) {
            return 'https://github.com/'.preg_replace('/\.git$/', '', $repository).'.git';
        }

        return $repository;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return list<mixed>
     */
    public static function cleanLines(array $lines): array
    {
        $lines = array_map(fn ($line) => is_string($line) ? trim($line) : $line, $lines);

        return array_values(array_filter($lines, fn ($line) => $line !== '' && $line !== null));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'repository' => ['required', 'string', 'max:500', 'regex:#^https://[^\s/]+/\S+$#'],
            'name' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:255', 'regex:/^[\w.\/-]+$/'],
            'cluster_id' => ['required', 'integer', 'exists:clusters,id'],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'env_profile' => ['required', Rule::enum(EnvProfileKind::class)],
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
            ...$this->eachProcess(fn (bool $exclusive, string $prefix) => Process::rules($exclusive, $prefix)),
            // Laravel merges a wildcard rule into the explicit rules above it, but an explicit rule below replaces it.
            'processes.*.name' => ['distinct'],
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
            'repository.regex' => 'Use owner/repo, or a full https address.',
            'env.*.key.regex' => 'Use letters, digits, and underscores. Do not start with a digit.',
            'domain.regex' => 'Enter a host name like shop.example.com.',
            'domain.unique' => 'This domain is already in use.',
            'processes.*.name.distinct' => 'Two processes have this name. Give each process its own name.',
            ...$this->eachProcess(fn (bool $exclusive, string $prefix) => Process::messages($exclusive, $prefix)),
        ];
    }

    /**
     * The limit on instances depends on the exclusive flag of the same row, so each row gets its own rules.
     *
     * @param  callable(bool, string): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function eachProcess(callable $build): array
    {
        $rows = $this->input('processes');

        return collect(is_array($rows) ? $rows : [])
            ->flatMap(fn (mixed $row, int|string $index) => $build(is_array($row) && filter_var($row['exclusive'] ?? false, FILTER_VALIDATE_BOOL), "processes.{$index}."))
            ->all();
    }
}
