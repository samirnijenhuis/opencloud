<?php

namespace App\Domains\CloudProviders\Checks;

use App\Domains\CloudProviders\AwsSignatureV4;
use App\Domains\CloudProviders\CheckResult;
use App\Domains\CloudProviders\ProviderCheck;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The pasted key is "<access key id>:<secret access key>", the line that the CLI command prints.
 */
final class AwsCheck implements ProviderCheck
{
    private const REGION = 'us-east-1';

    public function check(string $token): CheckResult
    {
        if (substr_count($token, ':') !== 1) {
            return CheckResult::invalid('Paste the access key ID and the secret access key as one line, joined by a colon: AKIA...:secret');
        }

        [$id, $secret] = explode(':', $token);

        $identity = $this->call($id, $secret, 'sts.amazonaws.com', 'sts', 'Action=GetCallerIdentity&Version=2011-06-15', [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'Accept' => 'application/json',
        ]);

        if (! $identity->successful()) {
            return CheckResult::invalid($this->error($identity));
        }

        // GetAuthorizationToken is the first call of each ECR push and has no side effect.
        $ecr = $this->call($id, $secret, 'api.ecr.'.self::REGION.'.amazonaws.com', 'ecr', '{}', [
            'Content-Type' => 'application/x-amz-json-1.1',
            'X-Amz-Target' => 'AmazonEC2ContainerRegistry_V20150921.GetAuthorizationToken',
        ]);

        $arn = $identity->json('GetCallerIdentityResponse.GetCallerIdentityResult.Arn') ?? 'the user';

        if (! $ecr->successful()) {
            return CheckResult::missing("The key works for {$arn}, but ECR refused it: {$this->error($ecr)}", ['ecr_push']);
        }

        return CheckResult::ok("The key works for {$arn} and can ask ECR for a push login. opencloud cannot test the other push rights without a change.");
    }

    /** @param  array<string, string>  $headers */
    private function call(string $id, string $secret, string $host, string $service, string $body, array $headers): Response
    {
        $signed = AwsSignatureV4::sign('POST', $host, '/', $body, self::REGION, $service, $id, $secret, new DateTimeImmutable, $headers);

        return Http::withHeaders($signed)->timeout(15)->withBody($body, $headers['Content-Type'])->post("https://{$host}/");
    }

    private function error(Response $response): string
    {
        $json = $response->json();
        $message = $json['Error']['Message'] ?? $json['message'] ?? $json['Message'] ?? null;

        if ($message === null && preg_match('#<Message>(.*?)</Message>#s', $response->body(), $match)) {
            $message = html_entity_decode($match[1]);
        }

        return $message ?? "AWS answered {$response->status()}.";
    }
}
