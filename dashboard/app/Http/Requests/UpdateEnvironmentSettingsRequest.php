<?php

namespace App\Http\Requests;

use App\Domains\Environments\Actions\UpdateEnvironmentSettings;
use App\Domains\Environments\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnvironmentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment'));
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('deploy_commands'))) {
            $this->merge(['deploy_commands' => self::cleanLines($this->input('deploy_commands'))]);
        }

        if (is_string($this->input('build_command'))) {
            $this->merge(['build_command' => self::cleanScript($this->input('build_command'))]);
        }
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
        return [
            'branch' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[\w.\/-]+$/'],
            'auto_deploy' => ['sometimes', 'boolean'],
            'size' => ['sometimes', Rule::enum(Size::class)],
            'min_instances' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_instances' => ['sometimes', 'integer', 'min:1', 'max:50', 'gte:min_instances'],
            'cpu_target' => ['sometimes', 'integer', 'min:10', 'max:95'],
            'health_path' => ['sometimes', 'required', 'string', 'max:255', 'starts_with:/'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'deploy_commands' => ['sometimes', 'array', 'max:20'],
            'deploy_commands.*' => ['string', 'max:1000'],
            'sleep_after_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sleep_after_minutes.*' => 'Enter a number of minutes from 5 to 1440.',
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->safe()->only(UpdateEnvironmentSettings::FIELDS);
    }
}
