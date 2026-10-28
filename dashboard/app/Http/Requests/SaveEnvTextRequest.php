<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveEnvTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['nullable', 'string', 'max:262144'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['text.max' => 'The text is too long. Remove some values.'];
    }
}
