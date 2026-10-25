<?php

namespace App\Http\Requests;

use App\Datastores\ManagedDatabase;
use App\Enums\Ability;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use Closure;
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
            'kind' => ['required', Rule::enum(DatastoreKind::class), function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('source') === DatastoreSource::Managed->value && ! in_array(DatastoreKind::tryFrom((string) $value), ManagedDatabase::OFFERED, true)) {
                    $fail('A managed database on DigitalOcean is Postgres only for now. Use managed Postgres, or MySQL in the cluster.');
                }
            }],
            'source' => ['nullable', Rule::enum(DatastoreSource::class)],
            // AttachDatastore owns the name rules, because they depend on the other databases of the environment.
            'name' => ['nullable', 'string'],
        ];
    }

    public function source(): DatastoreSource
    {
        return DatastoreSource::tryFrom((string) $this->validated('source')) ?? DatastoreSource::Standard;
    }
}
