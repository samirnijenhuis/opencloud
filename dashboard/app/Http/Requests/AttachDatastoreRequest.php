<?php

namespace App\Http\Requests;

use App\Enums\Ability;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachDatastoreRequest extends FormRequest
{
    /** A managed database costs money on the DigitalOcean account of the owner, so only the owner creates one. */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('update', $this->route('environment'))
            && ($this->input('source') !== DatastoreSource::Managed->value || $user->role->allows(Ability::ManageCredentials));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(DatastoreKind::class)],
            'source' => ['nullable', Rule::enum(DatastoreSource::class)],
        ];
    }

    public function source(): DatastoreSource
    {
        return DatastoreSource::tryFrom((string) $this->validated('source')) ?? DatastoreSource::Standard;
    }
}
