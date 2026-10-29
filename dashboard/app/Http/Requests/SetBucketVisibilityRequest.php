<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetBucketVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('attachment')->environment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'public' => ['required', 'boolean'],
        ];
    }
}
