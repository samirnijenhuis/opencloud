<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The delete also removes the data of each resource of the environment, so the user types its name first. */
class DeleteEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('environment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', Rule::in([$this->route('environment')->name])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm' => 'Type the name of the environment to confirm.'];
    }
}
