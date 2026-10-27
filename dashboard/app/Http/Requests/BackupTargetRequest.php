<?php

namespace App\Http\Requests;

use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Members\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackupTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->allows(Ability::MANAGE_CREDENTIALS);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $keyRequired = Rule::requiredIf(! BackupTarget::query()->exists());

        return [
            'endpoint' => ['required', 'url:http,https', 'max:255'],
            'bucket' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'path_style' => ['boolean'],
            'access_key' => [$keyRequired, 'nullable', 'string', 'max:255'],
            'secret_key' => [$keyRequired, 'nullable', 'string', 'max:255'],
        ];
    }
}
