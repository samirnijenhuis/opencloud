<?php

namespace App\Http\Requests;

use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('view', $this->environment())) {
            return false;
        }

        return $this->user()->role->allows(Ability::READ_LOGS);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'process' => ['required', 'string', Rule::in($this->environment()->processNames())],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after' => 'The end of the range must be after its start.',
            'search.max' => 'Use 200 characters or fewer in the search.',
        ];
    }

    private function environment(): Environment
    {
        return $this->route('environment');
    }
}
