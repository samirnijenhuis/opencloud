<?php

namespace App\Http\Requests;

use App\Enums\DatastoreKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachDatastoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['kind' => ['required', Rule::enum(DatastoreKind::class)]];
    }
}
