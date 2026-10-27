<?php

namespace App\Domains\Routing;

/**
 * Asks the ingress which certificate it serves for a domain. Tests bind a subclass that returns scripted certificates.
 */
class CertificateProbe
{
    private const TIMEOUT_SECONDS = 3;

    /** @return array<string, mixed>|null the parsed certificate, or null when the ingress serves none for this name */
    public function certificate(string $address, string $hostname): ?array
    {
        // The probe asks which certificate Caddy serves, not whether a browser trusts it, so a certificate of the local CA counts.
        $context = stream_context_create(['ssl' => [
            'peer_name' => $hostname,
            'SNI_enabled' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert' => true,
        ]]);
        $socket = @stream_socket_client("ssl://{$address}:443", $errno, $error, self::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return null;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        return $certificate === null ? null : (openssl_x509_parse($certificate) ?: null);
    }

    /** @param  array<string, mixed>|null  $certificate */
    public static function covers(?array $certificate, string $hostname, int $now): bool
    {
        if ($certificate === null || $now < ($certificate['validFrom_time_t'] ?? PHP_INT_MAX) || $now > ($certificate['validTo_time_t'] ?? 0)) {
            return false;
        }

        $hostname = strtolower($hostname);
        $parent = strstr($hostname, '.');

        foreach (explode(',', $certificate['extensions']['subjectAltName'] ?? '') as $entry) {
            $name = strtolower(trim(str_replace('DNS:', '', trim($entry))));

            if ($name === $hostname || ($parent !== false && str_starts_with($name, '*.') && substr($name, 1) === $parent)) {
                return true;
            }
        }

        return false;
    }
}
