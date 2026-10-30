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
            'preview_domain' => ['nullable', 'string', 'max:200', 'regex:'.StoreApplicationRequest::HOSTNAME],
            'preview_labels' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preview_domain.regex' => 'Enter a domain like preview.example.com.',
            'preview_labels.max' => 'Keep the labels to 500 characters or fewer.',
        ];
    }

    /**
     * The form sends the labels as one text with commas between them.
     *
     * @return array{previews_enabled: bool, preview_domain: ?string, preview_labels: list<string>}
     */
    public function settings(): array
    {
        return [
            'previews_enabled' => $this->boolean('previews_enabled'),
            'preview_domain' => $this->validated('preview_domain'),
            'preview_labels' => collect(explode(',', (string) $this->validated('preview_labels')))
                ->map(fn (string $label) => trim($label))
                ->reject(fn (string $label) => $label === '')
                ->values()
                ->all(),
        ];
    }
}
