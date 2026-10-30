<?php

namespace App\Http\Requests;

use App\Domains\Previews\PreviewHostname;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePreviewSettingsRequest extends FormRequest
{
    private const PLACEMENT = 'Put {pr_number} once in the first part of the address, for example pr-{pr_number}.previews.example.com.';

    private const HOST = 'Enter an address like pr-{pr_number}.previews.example.com.';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    protected function prepareForValidation(): void
    {
        $domain = $this->input('preview_domain');

        if (is_string($domain)) {
            $domain = DomainRequest::normalizeHostname($domain);
            $this->merge(['preview_domain' => $domain === '' ? null : $domain]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'previews_enabled' => ['sometimes', 'boolean'],
            'preview_domain' => ['sometimes', 'nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail) {
                // One wildcard record covers one label, so the number sits in the first label.
                if (substr_count($value, PreviewHostname::PR_NUMBER) !== 1 || ! str_contains(explode('.', $value)[0], PreviewHostname::PR_NUMBER)) {
                    $fail(self::PLACEMENT);
                } elseif (! preg_match(StoreApplicationRequest::HOSTNAME, str_replace(PreviewHostname::PR_NUMBER, '999999', $value))) {
                    $fail(self::HOST);
                }
            }],
            'preview_labels' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preview_domain.max' => self::HOST,
            'preview_labels.max' => 'Keep the labels to 500 characters or fewer.',
        ];
    }

    /**
     * The row switch sends only the switch. The template sheet sends the address and the labels.
     * The sheet sends the labels as one text with commas between them.
     *
     * @return array{previews_enabled?: bool, preview_domain?: ?string, preview_labels?: list<string>}
     */
    public function settings(): array
    {
        $settings = $this->validated();

        if (array_key_exists('previews_enabled', $settings)) {
            $settings['previews_enabled'] = $this->boolean('previews_enabled');
        }

        if (array_key_exists('preview_labels', $settings)) {
            $settings['preview_labels'] = collect(explode(',', (string) $settings['preview_labels']))
                ->map(fn (string $label) => trim($label))
                ->reject(fn (string $label) => $label === '')
                ->values()
                ->all();
        }

        return $settings;
    }
}
