<?php

namespace App\Domains\GitHub;

use RuntimeException;

/**
 * The JSON Web Token that a GitHub App signs with its private key to call the API as the app.
 */
final class AppJwt
{
    /** GitHub refuses a token that expires more than 10 minutes after it was issued. */
    public const LIFETIME_SECONDS = 600;

    /** The issue time goes back one minute, because the clock of GitHub can run behind ours. */
    private const CLOCK_DRIFT_SECONDS = 60;

    /** @throws RuntimeException when the private key cannot sign */
    public static function sign(int $appId, string $privateKey, int $now): string
    {
        $issuedAt = $now - self::CLOCK_DRIFT_SECONDS;
        $header = self::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::encode(json_encode(['iat' => $issuedAt, 'exp' => $issuedAt + self::LIFETIME_SECONDS, 'iss' => $appId], JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false || ! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The private key of the GitHub App cannot sign a token.');
        }

        return "{$header}.{$claims}.".self::encode($signature);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
