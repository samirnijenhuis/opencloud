<?php

namespace App\Http\Requests;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

class RestoreBackupRequest extends FormRequest
{
    /** A restore replaces data, so it needs an owner or an admin in every environment. */
    public function authorize(): bool
    {
        return $this->user()->role->allows(Ability::ChangeProtectedEnvironment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => ['accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm.accepted' => 'Confirm that the current data is replaced.'];
    }
}
