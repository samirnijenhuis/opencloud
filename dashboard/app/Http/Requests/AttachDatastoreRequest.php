<?php

namespace App\Http\Requests;

use App\Datastores\Provisioners;
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
                $kind = DatastoreKind::tryFrom((string) $value);
                $source = DatastoreSource::tryFrom((string) $this->input('source')) ?? DatastoreSource::Standard;
                $notOffered = $kind === null ? null : Provisioners::notOffered($kind, $source);

                if ($notOffered !== null) {
                    $fail($notOffered);
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
