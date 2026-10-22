<?php

namespace App\Http\Requests;

use App\Clusters\ClusterRegistry;
use App\Models\Cluster;
use Illuminate\Foundation\Http\FormRequest;

class ConnectClusterRequest extends FormRequest
{
    /** A host with an optional port, then an optional path, for example "registry.example.com/team" or "203.0.113.5:5000". */
    private const REGISTRY = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?(\/[a-z0-9._\/-]+)?$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', Cluster::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('registry_url'))) {
            $this->merge(['registry_url' => strtolower(ClusterRegistry::normalizeAddress($this->input('registry_url')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', 'unique:clusters,name'],
            'kubeconfig' => ['required', 'string', 'max:65535'],
            'registry_url' => ['required', 'string', 'max:255', 'regex:'.self::REGISTRY],
            'registry_username' => ['required', 'string', 'max:255'],
            'registry_password' => ['required', 'string', 'max:4096'],
            'registry_insecure' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another cluster has this name.',
            'registry_url.regex' => 'Enter a registry address like registry.example.com/team.',
        ];
    }
}
