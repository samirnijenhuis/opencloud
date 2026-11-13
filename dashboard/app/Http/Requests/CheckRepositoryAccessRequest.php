<?php

namespace App\Http\Requests;

use App\Domains\Applications\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

/** The create page asks before the application exists, so only a user who may create one can make opencloud read a repository. */
class CheckRepositoryAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('repository'))) {
            $this->merge(['repository' => StoreApplicationRequest::normalizeRepository($this->input('repository'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return StoreApplicationRequest::repositoryRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StoreApplicationRequest::repositoryMessages();
    }

    /** The unsaved application that the probe reads. */
    public function application(): Application
    {
        return new Application(['repository' => $this->validated('repository')]);
    }
}
