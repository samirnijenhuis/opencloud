<?php

namespace App\CloudProviders;

/**
 * The answer of one provider check. The message is plain text for the owner and never holds the token.
 */
final readonly class CheckResult
{
    /**
     * @param  list<string>  $missing  right ids from the registry
     * @param  array<string, mixed>  $details  data the provider answered, for example the Cloudflare zones
     */
    private function __construct(
        public CheckStatus $status,
        public string $message,
        public array $missing = [],
        public array $details = [],
    ) {}

    /** @param  array<string, mixed>  $details */
    public static function ok(string $message, array $details = []): self
    {
        return new self(CheckStatus::Ok, $message, [], $details);
    }

    /** A provider message could echo the key. */
    public function withoutSecret(string $secret): self
    {
        return new self($this->status, $secret === '' ? $this->message : str_replace($secret, '[hidden]', $this->message), $this->missing, $this->details);
    }

    public static function invalid(string $message): self
    {
        return new self(CheckStatus::Invalid, $message);
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $details
     */
    public static function missing(string $message, array $missing, array $details = []): self
    {
        return new self(CheckStatus::MissingRights, $message, $missing, $details);
    }
}
