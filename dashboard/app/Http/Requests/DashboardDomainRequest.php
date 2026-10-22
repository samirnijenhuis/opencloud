<?php

namespace App\Http\Requests;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

class DashboardDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->allows(Ability::ManageCredentials);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'regex:'.StoreApplicationRequest::HOSTNAME, 'unique:domains,hostname'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('hostname'))) {
            $this->merge(['hostname' => DomainRequest::normalizeHostname($this->input('hostname'))]);
        }
    }
}
