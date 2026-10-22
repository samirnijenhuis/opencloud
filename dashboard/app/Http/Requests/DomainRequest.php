<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('environment')->application);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('hostname'))) {
            $this->merge(['hostname' => self::normalizeHostname($this->input('hostname'))]);
        }
    }

    /** Accepts a pasted address like "https://Shop.Example.com/" and keeps the host name. */
    public static function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname);
        $hostname = explode('/', $hostname)[0];

        return rtrim($hostname, '.');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'regex:'.StoreApplicationRequest::HOSTNAME, 'unique:domains,hostname', 'unique:dashboard_domains,hostname'],
            'manage_dns' => ['sometimes', 'boolean'],
            'proxied' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'hostname.regex' => 'Enter a host name like shop.example.com.',
            'hostname.unique' => 'This domain is already in use.',
        ];
    }
}
