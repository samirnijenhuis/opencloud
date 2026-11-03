<?php

namespace App\Http\Requests;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Environments\Enums\EnvironmentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemovePreviewAttachmentRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(DatastoreKind::class)],
            'name' => ['nullable', 'string'],
        ];
    }

    public function kind(): DatastoreKind
    {
        return $this->enum('kind', DatastoreKind::class);
    }
}
