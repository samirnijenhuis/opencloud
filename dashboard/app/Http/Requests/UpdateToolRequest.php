<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tool')->environment);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('hostname'))) {
            $this->merge(['hostname' => DomainRequest::normalizeHostname($this->input('hostname'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tool = $this->route('tool');

        return ['hostname' => AddToolRequest::hostnameRules($tool->environment, $tool)];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return AddToolRequest::hostnameMessages();
    }
}
