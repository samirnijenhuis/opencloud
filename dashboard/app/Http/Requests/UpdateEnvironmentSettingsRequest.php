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
            $this->merge(['deploy_commands' => StoreApplicationRequest::cleanLines($this->input('deploy_commands'))]);
        }

        if (is_string($this->input('build_command'))) {
            $this->merge(['build_command' => StoreApplicationRequest::cleanScript($this->input('build_command'))]);
        }
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
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->safe()->only(UpdateEnvironmentSettings::FIELDS);
    }
}
