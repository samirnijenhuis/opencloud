<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the application a name.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
        ];
    }
}
