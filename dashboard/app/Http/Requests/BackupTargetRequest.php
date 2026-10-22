<?php

namespace App\Http\Requests;

use App\Enums\Ability;
use App\Models\BackupTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackupTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->allows(Ability::ManageCredentials);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $keyRequired = Rule::requiredIf(! BackupTarget::query()->exists());

        return [
            'endpoint' => ['required', 'url:http,https', 'max:255'],
            'bucket' => ['required', 'string', 'max:255'],
            'access_key' => [$keyRequired, 'nullable', 'string', 'max:255'],
            'secret_key' => [$keyRequired, 'nullable', 'string', 'max:255'],
        ];
    }
}
