<?php

namespace App\Http\Requests;

use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnvVarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Environment $environment */
        $environment = $this->route('environment');

        return [
            'key' => [
                'required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
                Rule::notIn($environment->envVars()->where('source', EnvVarSource::Datastore)->pluck('key')->all()),
            ],
            'value' => ['nullable', 'string', 'max:65535'],
            'is_secret' => ['boolean'],
            'available_at' => ['required', Rule::enum(EnvVarAvailability::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.regex' => 'Use letters, digits, and underscores. Do not start with a digit.',
            'key.not_in' => 'A datastore sets this value. Detach the datastore to remove it.',
        ];
    }
}
