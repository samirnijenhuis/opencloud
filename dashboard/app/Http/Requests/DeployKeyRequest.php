<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Adding, replacing, and removing the deploy key share the one check. Only adding takes input: the user's own private key, or nothing for a new key. */
class DeployKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'deploy_private_key' => ['nullable', 'string', 'max:16384'],
        ];
    }
}
