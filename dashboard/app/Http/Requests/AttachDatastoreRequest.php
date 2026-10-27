<?php

namespace App\Http\Requests;

use App\Domains\Datastores\Connection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\ExternalConnectionForm;
use App\Domains\Datastores\Provisioners;
use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Enums\Role;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachDatastoreRequest extends FormRequest
{
    /** A managed resource costs money on the cloud account of the owner, so only the owner creates one. */
    public static function sourceRefusal(Role $role, DatastoreSource $source): ?string
    {
        return $source === DatastoreSource::MANAGED && ! $role->allows(Ability::MANAGE_CREDENTIALS)
            ? 'Only an owner can add a managed resource.'
            : null;
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $source = DatastoreSource::tryFrom((string) $this->input('source')) ?? DatastoreSource::STANDARD;

        return $user->can('update', $this->route('environment')) && self::sourceRefusal($user->role, $source) === null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $external = $this->externalKind();

        return [
            'kind' => ['required', Rule::enum(DatastoreKind::class), function (string $attribute, mixed $value, Closure $fail) {
                $kind = DatastoreKind::tryFrom((string) $value);
                $source = DatastoreSource::tryFrom((string) $this->input('source')) ?? DatastoreSource::STANDARD;
                $notOffered = $kind === null ? null : Provisioners::notOffered($kind, $source);

                if ($notOffered !== null) {
                    $fail($notOffered);
                }
            }],
            'source' => ['nullable', Rule::enum(DatastoreSource::class)],
            // AttachDatastore owns the name rules, because they depend on the other databases of the environment.
            'name' => ['nullable', 'string'],
            ...($external === null ? [] : ExternalConnectionForm::rules($external)),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $external = $this->externalKind();

        return $external === null ? [] : ExternalConnectionForm::messages($external);
    }

    public function source(): DatastoreSource
    {
        return DatastoreSource::tryFrom((string) $this->validated('source')) ?? DatastoreSource::STANDARD;
    }

    /** What the user typed for a resource that they run, or null for a source that makes its own connection. */
    public function connection(): ?Connection
    {
        $external = $this->externalKind();

        return $external === null ? null : ExternalConnectionForm::connection($external, $this->validated(), $this->validated('name'));
    }

    /** The kind when the user brings their own server. The other sources ignore the address fields. */
    private function externalKind(): ?DatastoreKind
    {
        return $this->input('source') === DatastoreSource::EXTERNAL->value ? DatastoreKind::tryFrom((string) $this->input('kind')) : null;
    }
}
