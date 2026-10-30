<?php

namespace App\Http\Requests;

use App\Domains\Applications\Actions\AddDeployKey;
use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\GitHub\RepositoryName;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('repository'))) {
            $this->merge(['repository' => self::normalizeRepository($this->input('repository'))]);
        }
    }

    /** "owner/repo" and the SSH address "git@github.com:owner/repo.git" become a GitHub clone URL. A full https URL stays as it is. */
    public static function normalizeRepository(string $repository): string
    {
        $repository = trim($repository);

        if (preg_match('#^(?:git@github\.com:)?([\w.-]+/[\w.-]+?)(?:\.git)?$#', $repository, $match) === 1) {
            return "https://github.com/{$match[1]}.git";
        }

        return $repository;
    }

    /**
     * CheckRepositoryAccessRequest shares the repository and branch rules.
     *
     * @return array<string, mixed>
     */
    public static function repositoryRules(): array
    {
        return [
            'repository' => ['required', 'string', 'max:500', 'regex:#^https://[^\s/]+/\S+$#'],
            'branch' => ['nullable', 'string', 'max:255', 'regex:/^[\w.\/-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public static function repositoryMessages(): array
    {
        return [
            'repository.regex' => 'Use owner/repo, git@github.com:owner/repo.git, or a full https address.',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...self::repositoryRules(),
            'name' => ['nullable', 'string', 'max:100'],
            'deploy_key' => ['boolean', $this->onGitHub(...)],
            'deploy_private_key' => ['nullable', 'string', 'max:16384', 'prohibited_if_accepted:deploy_key', $this->onGitHub(...)],
            'cluster_id' => ['required', 'integer', 'exists:clusters,id'],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'port' => ['nullable', 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...self::repositoryMessages(),
            'deploy_private_key.prohibited_if_accepted' => 'Choose a new key or your own key. Do not choose both.',
        ];
    }

    /**
     * AddDeployKey refuses a key outside github.com too, but CreateApplication reads the repository first. Git ignores
     * the key for another host, so without this check an own key would get the wrong error.
     */
    private function onGitHub(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value && RepositoryName::parse((string) $this->input('repository')) === null) {
            $fail(AddDeployKey::GITHUB_ONLY);
        }
    }
}
