<?php

namespace App\Http\Requests;

use App\Domains\Environments\Enums\EnvironmentKind;
use Illuminate\Foundation\Http\FormRequest;

class ShowPullRequestsRequest extends FormRequest
{
    /** Only the preview template makes previews, so only its page lists the pull requests. */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        if ($environment->kind !== EnvironmentKind::PREVIEW_TEMPLATE) {
            return false;
        }

        return $this->user()->can('view', $environment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
