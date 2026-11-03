<?php

namespace App\Http\Requests;

use App\Domains\Environments\Enums\EnvironmentKind;
use Illuminate\Foundation\Http\FormRequest;

class DeployPullRequestRequest extends FormRequest
{
    /** A preview copies the preview template, so the right to deploy the template is the right to deploy a preview. */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        if ($environment->kind !== EnvironmentKind::PREVIEW_TEMPLATE) {
            return false;
        }

        return $this->user()->can('deploy', $environment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
