<?php

namespace App\CloudProviders;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Signs one AWS request with Signature Version 4. It exists so the dashboard needs no AWS SDK for two read calls.
 * The path must be "/" or already URI encoded, and the query must be empty.
 */
final class AwsSignatureV4
{
    /**
     * @param  array<string, string>  $headers  extra headers to sign, for example content-type
     * @return array<string, string> the headers to send, including authorization
     */
    public static function sign(
        string $method,
        string $host,
        string $path,
        string $body,
        string $region,
        string $service,
        string $accessKeyId,
        string $secretAccessKey,
        DateTimeImmutable $now,
        array $headers = [],
    ): array {
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $date = substr($stamp, 0, 8);

        $signed = ['host' => $host, 'x-amz-date' => $stamp];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim($value);
        }
        ksort($signed, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }
        $signedNames = implode(';', array_keys($signed));

        $canonicalRequest = implode("\n", [$method, $path, '', $canonicalHeaders, $signedNames, hash('sha256', $body)]);
        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $stamp, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $date, "AWS4{$secretAccessKey}", true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $key);

        return [
            ...$headers,
            'X-Amz-Date' => $stamp,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$scope}, SignedHeaders={$signedNames}, Signature={$signature}",
        ];
    }
}
