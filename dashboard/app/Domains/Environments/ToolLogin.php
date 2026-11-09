<?php

namespace App\Domains\Environments;

use App\Domains\Environments\Models\Environment;

/**
 * The basic auth login in front of the tools of one environment. The app key and the environment id give the password,
 * so no column stores it, and it ends with the environment.
 */
final readonly class ToolLogin
{
    public const USERNAME = 'opencloud';

    private const BCRYPT_ALPHABET = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private function __construct(public string $username, public string $password, private string $salt) {}

    public static function for(Environment $environment): self
    {
        $key = (string) config('app.key');
        $salt = array_map(fn (string $byte) => self::BCRYPT_ALPHABET[ord($byte) % 64], str_split(hash_hmac('sha256', "tools-salt:{$environment->id}", $key, true)));

        return new self(self::USERNAME, substr(hash_hmac('sha256', "tools:{$environment->id}", $key), 0, 24), implode(array_slice($salt, 0, 22)));
    }

    /**
     * The bcrypt hash that Caddy checks. CaddySync sends the full config every five minutes, and Caddy reloads only a
     * config that changed, so the salt is fixed and each call gives the same hash.
     */
    public function hash(): string
    {
        return crypt($this->password, '$2y$10$'.$this->salt);
    }
}
