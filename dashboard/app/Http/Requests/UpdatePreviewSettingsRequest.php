<?php

namespace App\Http\Requests;

use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Previews\PreviewHostname;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

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
                } elseif (in_array(strstr($value, PreviewHostname::PR_NUMBER, before_needle: true), self::toolPrefixes(), true)) {
                    // The preview and its tool would have the same hostname, and Caddy keeps one route for each hostname.
                    $fail('Start the address with a different word than '.Arr::join(self::toolPrefixes(), ', ', ' or ').'. The preview tools use those addresses.');
                }
            }],
            'preview_labels' => ['sometimes', 'nullable', 'string', 'max:500'],
            'max_previews' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'preview_tools' => ['sometimes', 'array'],
            'preview_tools.*' => [Rule::enum(ToolKind::class), 'distinct'],
        ];
    }

    /** @return list<string> the start of the address of each tool, for example mail- */
    private static function toolPrefixes(): array
    {
        return array_map(fn (ToolKind $tool) => $tool->hostnamePrefix().'-', ToolKind::cases());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preview_domain.max' => self::HOST,
            'preview_labels.max' => 'Keep the labels to 500 characters or fewer.',
            'max_previews.*' => 'Enter a number from 0 to 1000. 0 means no limit.',
            'preview_tools.*' => 'Choose only the tools in the list.',
        ];
    }

    /**
     * The row switch sends only the switch. The template sheet sends the address, the labels, the limit, and the tools.
     * The sheet sends the labels as one text with commas between them. The tools keep the order of the enum, so the
     * same choice always stores the same value.
     *
     * @return array{previews_enabled?: bool, preview_domain?: ?string, preview_labels?: list<string>, max_previews?: int, preview_tools?: list<ToolKind>}
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
        if (array_key_exists('preview_tools', $settings)) {
            $settings['preview_tools'] = array_values(array_filter(ToolKind::cases(), fn (ToolKind $tool) => in_array($tool->value, $settings['preview_tools'], true)));
        }

        return $settings;
    }
}
