<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreviewSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    protected function prepareForValidation(): void
    {
        // The owner may paste the wildcard record, for example "*.preview.example.com".
        $domain = $this->input('preview_domain');
        $this->merge(['preview_domain' => is_string($domain) && trim($domain) !== '' ? DomainRequest::normalizeHostname(preg_replace('/^\s*\*\./', '', $domain)) : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'previews_enabled' => ['required', 'boolean'],
            'allow_forks' => ['required', 'boolean'],
            'preview_domain' => ['nullable', 'string', 'max:200', 'regex:'.StoreApplicationRequest::HOSTNAME],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preview_domain.regex' => 'Enter a domain like preview.example.com.',
        ];
    }

    /** @return array{previews_enabled: bool, allow_forks: bool, preview_domain: ?string} */
    public function settings(): array
    {
        return [
            'previews_enabled' => $this->boolean('previews_enabled'),
            'allow_forks' => $this->boolean('allow_forks'),
            'preview_domain' => $this->validated('preview_domain'),
        ];
    }
}
