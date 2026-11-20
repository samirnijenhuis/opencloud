<?php

namespace App\Http\Requests;

use App\Domains\Members\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

class ViewUpdatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->allows(Ability::UPGRADE_OPENCLOUD);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
