<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\EnvVar;

final readonly class SecretMasker
{
    public const MASK = '********';

    /** A shorter value would mask common words and numbers in every line. */
    private const MIN_LENGTH = 4;

    /** @var list<string> longest first, so a secret that contains another secret is masked whole */
    private array $secrets;

    /** @param  iterable<string>  $secrets */
    public function __construct(iterable $secrets)
    {
        $values = [];

        foreach ($secrets as $secret) {
            // Logs are masked line by line, so each line of a multi-line secret is masked on its own.
            foreach ([$secret, ...preg_split('/\R/', $secret)] as $value) {
                if (strlen($value) >= self::MIN_LENGTH) {
                    $values[$value] = true;
                }
            }
        }

        $values = array_map(strval(...), array_keys($values));
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $this->secrets = $values;
    }

    /**
     * A database that is not the default has its password only inside a URL value, URL-encoded. A command can print
     * that password alone, so the passwords of the attachments count too, plain and URL-encoded.
     */
    public static function forEnvironment(Environment $environment): self
    {
        $passwords = $environment->datastoreAttachments()->get()->pluck('password')->filter();

        return new self([
            ...$environment->envVars()->where('is_secret', true)->get()->map(fn (EnvVar $var) => (string) $var->value),
            ...$passwords,
            ...$passwords->map(rawurlencode(...)),
        ]);
    }

    public function mask(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, self::MASK, $text);
    }
}
