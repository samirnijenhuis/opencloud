<?php

namespace App\Http\Requests;

use App\Clusters\DigitalOceanCluster;
use App\Models\Cluster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateClusterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Cluster::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', 'unique:clusters,name'],
            'region' => ['required', Rule::in(array_keys(DigitalOceanCluster::REGIONS))],
            'node_size' => ['required', Rule::in(array_keys(DigitalOceanCluster::NODE_SIZES))],
            'min_nodes' => ['required', 'integer', 'min:1', 'max:'.DigitalOceanCluster::MAX_NODES],
            'max_nodes' => ['required', 'integer', 'gte:min_nodes', 'max:'.DigitalOceanCluster::MAX_NODES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another cluster has this name.',
            'name.regex' => 'Use lowercase letters, digits, and dashes. Start and end with a letter or a digit.',
            'max_nodes.gte' => 'The maximum must be at least the minimum.',
        ];
    }

    /** @return array{name: string, region: string, node_size: string, min_nodes: int, max_nodes: int} */
    public function cluster(): array
    {
        return [...$this->validated(), 'min_nodes' => $this->integer('min_nodes'), 'max_nodes' => $this->integer('max_nodes')];
    }
}
