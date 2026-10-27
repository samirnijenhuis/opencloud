<?php

namespace App\Http\Requests;

use App\Domains\Environments\Models\Process;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('process')->environment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...Process::rules(), ...Process::computeRules()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return Process::messages();
    }
}
