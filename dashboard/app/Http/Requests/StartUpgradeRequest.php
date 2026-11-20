<?php

namespace App\Http\Requests;

use App\Domains\Members\Enums\Ability;
use App\Domains\Upgrades\Jobs\CheckLatestRelease;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->allows(Ability::UPGRADE_OPENCLOUD);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string', Rule::in(array_filter([CheckLatestRelease::newerRelease()]))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target.in' => 'Opencloud cannot update to this release. Reload the page to see the latest release.',
        ];
    }
}
