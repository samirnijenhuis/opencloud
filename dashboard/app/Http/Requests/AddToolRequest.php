<?php

namespace App\Http\Requests;

use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use App\Domains\Previews\PreviewHostname;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment'));
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
        $environment = $this->route('environment');

        return [
            'kind' => ['required', Rule::enum(ToolKind::class), Rule::unique('tools')->where('environment_id', $environment->id), function (string $attribute, mixed $value, Closure $fail) use ($environment) {
                if ($environment->kind === EnvironmentKind::PERMANENT) {
                    $fail('Tools run only in previews. Add the tool to the preview template.');
                }
            }],
            'hostname' => self::hostnameRules($environment),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.required' => 'Choose a tool.',
            'kind.enum' => 'Choose a tool.',
            'kind.unique' => 'This environment already has this tool.',
            ...self::hostnameMessages(),
        ];
    }

    /**
     * The rules of the hostname of a new or a changed tool. On the preview template it is a pattern with the rule of
     * the preview address. On a preview it is a plain hostname. Caddy keeps one route for each hostname, so no other
     * tool, domain, or preview address may have it.
     *
     * @return list<mixed>
     */
    public static function hostnameRules(Environment $environment, ?Tool $tool = null): array
    {
        return [
            'required',
            'string',
            $environment->kind === EnvironmentKind::PREVIEW_TEMPLATE ? UpdatePreviewSettingsRequest::pattern(...) : 'regex:'.StoreApplicationRequest::HOSTNAME,
            Rule::notIn(array_filter([(new PreviewHostname)->pattern($environment->application)])),
            Rule::unique('applications', 'preview_domain'),
            Rule::unique('tools', 'hostname')->ignore($tool),
            Rule::unique('domains', 'hostname'),
            Rule::unique('dashboard_domains', 'hostname'),
        ];
    }

    /** @return array<string, string> */
    public static function hostnameMessages(): array
    {
        return [
            'hostname.required' => 'Enter the address of the tool.',
            'hostname.regex' => 'Enter an address like mail-12.previews.example.com.',
            'hostname.not_in' => 'Each preview uses this address. Use a different address.',
            'hostname.unique' => 'This address is already in use. Use a different address.',
        ];
    }
}
