<?php

namespace App\CloudProviders;

use Illuminate\Http\Client\ConnectionException;

/**
 * Everything the dashboard knows about one provider key. The table lives in Providers.
 */
final readonly class ProviderGuide
{
    /**
     * @param  class-string<ProviderCheck>  $checker
     * @param  list<string>  $steps  what to do on the provider page, in order
     * @param  list<Right>  $rights
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $use,
        public ?string $url,
        public array $steps,
        public array $rights,
        public ?string $command,
        public string $pasteHint,
        public string $checker,
        public ?string $checkUrl = null,
    ) {}

    /** The page that CI requests. It is the link itself unless the provider blocks bots on that page. */
    public function linkToCheck(): ?string
    {
        return $this->checkUrl ?? $this->url;
    }

    public function check(string $token): CheckResult
    {
        try {
            return app($this->checker)->check($token);
        } catch (ConnectionException) {
            return CheckResult::invalid("Opencloud cannot reach {$this->label} now. Try again in a minute.");
        }
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function labelsOf(array $ids): array
    {
        return array_values(array_map(
            fn (string $id) => collect($this->rights)->firstWhere('id', $id)?->label ?? $id,
            $ids,
        ));
    }

    /** A key is kept when it works and no required right is missing. */
    public function accepts(CheckResult $result): bool
    {
        $optional = collect($this->rights)->where('optional', true)->pluck('id')->all();

        return match ($result->status) {
            CheckStatus::Ok => true,
            CheckStatus::MissingRights => array_diff($result->missing, $optional) === [],
            CheckStatus::Invalid => false,
        };
    }
}
