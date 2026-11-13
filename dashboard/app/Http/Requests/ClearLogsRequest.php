<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClearLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
