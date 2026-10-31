<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowRepositoryAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('environment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
