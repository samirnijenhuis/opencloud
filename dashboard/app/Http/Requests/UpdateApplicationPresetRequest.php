<?php

namespace App\Http\Requests;

use App\Enums\ApplicationType;
use App\Presets\EnvProfileKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'env_profile' => ['required', Rule::enum(EnvProfileKind::class)],
            'port' => ['nullable', 'integer', 'between:1,65535'],
        ];
    }
}
