<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The delete also removes each environment and the data of its resources, so the user types the name first. */
class DeleteApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', Rule::in([$this->route('application')->name])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm' => 'Type the name of the application to confirm.'];
    }
}
