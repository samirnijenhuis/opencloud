<?php

namespace App\Http\Requests;

use App\Presets\EnvProfileKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationEnvProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'env_profile' => ['required', Rule::enum(EnvProfileKind::class)],
        ];
    }
}
