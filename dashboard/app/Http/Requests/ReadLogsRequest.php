<?php

namespace App\Http\Requests;

use App\Domains\Environments\Models\Environment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReadLogsRequest extends FormRequest
{
    /** The log store keeps the lines for 7 days, and Loki refuses a range longer than 30 days. */
    private const MAX_RANGE_SECONDS = 7 * 86400;

    public function authorize(): bool
    {
        return $this->user()->can('readLogs', $this->environment());
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

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->date('from')->diffInSeconds($this->date('to')) > self::MAX_RANGE_SECONDS) {
                $validator->errors()->add('to', 'Pick a range of 7 days or less. The log store keeps the lines for 7 days.');
            }
        }];
    }

    private function environment(): Environment
    {
        return $this->route('environment');
    }
}
