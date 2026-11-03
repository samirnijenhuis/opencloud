<?php

namespace App\Http\Requests;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Provisioners;
use App\Domains\Environments\Enums\EnvironmentKind;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPreviewAttachmentRequest extends FormRequest
{
    /** Only the preview template has the list of resources that each new preview gets. */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        if ($environment->kind !== EnvironmentKind::PREVIEW_TEMPLATE) {
            return false;
        }

        return $this->user()->can('update', $environment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A preview always gets a standard resource, so the list holds only the kinds that the cluster runs itself.
            'kind' => ['required', Rule::enum(DatastoreKind::class), function (string $attribute, mixed $value, Closure $fail) {
                $notOffered = Provisioners::notOffered(DatastoreKind::from($value), DatastoreSource::STANDARD);

                if ($notOffered !== null) {
                    $fail($notOffered);
                }
            }],
            // AddPreviewAttachment owns the name rules, because they depend on the other entries of the list.
            'name' => ['nullable', 'string'],
        ];
    }

    public function kind(): DatastoreKind
    {
        return $this->enum('kind', DatastoreKind::class);
    }
}
