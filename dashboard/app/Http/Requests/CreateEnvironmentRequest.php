<?php

namespace App\Http\Requests;

use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEnvironmentRequest extends FormRequest
{
    /** The name goes into the free domain of the environment, so it is one DNS label. */
    private const NAME = '/^[a-z0-9]([a-z0-9-]{0,28}[a-z0-9])?$/';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A preview takes the name pr-<number> when its pull request opens.
            'name' => ['required', 'string', 'regex:'.self::NAME, 'not_regex:/^pr-\d+$/', Rule::unique(Environment::class)->where('application_id', $this->route('application')->id)],
            'branch' => ['required', 'string', 'max:255', 'regex:/^[\w.\/-]+$/'],
            'copy_from' => ['required', Rule::in(['production', 'preview_template'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the environment a name.',
            'name.regex' => 'Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.',
            'name.not_regex' => 'A name like pr-12 belongs to the preview of a pull request. Choose another name.',
            'name.unique' => 'This application already has an environment with this name.',
            'branch' => 'Enter a branch name, for example staging.',
            'copy_from' => 'Choose production or the preview template.',
        ];
    }

    public function copyFrom(): Environment
    {
        $environments = $this->route('application')->environments();

        return $this->validated('copy_from') === 'preview_template'
            ? $environments->where('kind', EnvironmentKind::PREVIEW_TEMPLATE)->sole()
            : $environments->where('name', 'production')->sole();
    }
}
