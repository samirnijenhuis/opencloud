<?php

namespace Tests\Feature\CloudProviders;

use App\CloudProviders\CheckStatus;
use App\CloudProviders\Providers;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderChecksTest extends TestCase
{
    private const DO = 'https://api.digitalocean.com/v2';

    private const CF = 'https://api.cloudflare.com/client/v4';

    public function test_digitalocean_ok_when_every_probe_answers(): void
    {
        Http::fake([
            self::DO.'/account' => Http::response(['account' => ['email' => 'me@example.com']]),
            self::DO.'/kubernetes/clusters*' => Http::response(['kubernetes_clusters' => []]),
            self::DO.'/registry' => Http::response(['id' => 'not_found', 'message' => 'no registry'], 404),
            self::DO.'/vpcs*' => Http::response(['vpcs' => []]),
            self::DO.'/databases*' => Http::response(['databases' => []]),
            self::DO.'/spaces/keys*' => Http::response(['keys' => []]),
        ]);

        $result = Providers::find('digitalocean')->check('dop_v1_abc');

        $this->assertSame(CheckStatus::Ok, $result->status);
        $this->assertSame('The token works for the account me@example.com.', $result->message);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer dop_v1_abc'));
        Http::assertSentCount(6);
    }

    public function test_digitalocean_invalid_uses_the_message_of_digitalocean(): void
    {
        Http::fake([self::DO.'/account' => Http::response(['id' => 'Unauthorized', 'message' => 'Unable to authenticate you'], 401)]);

        $result = Providers::find('digitalocean')->check('bad');

        $this->assertSame(CheckStatus::Invalid, $result->status);
        $this->assertSame('Unable to authenticate you', $result->message);
        Http::assertSentCount(1);
    }

    public function test_digitalocean_names_each_missing_right(): void
    {
        Http::fake([
            self::DO.'/account' => Http::response(['account' => ['email' => 'me@example.com']]),
            self::DO.'/kubernetes/clusters*' => Http::response(['id' => 'forbidden', 'message' => 'You are not authorized to perform this operation'], 403),
            self::DO.'/registry' => Http::response(['id' => 'forbidden', 'message' => 'You are not authorized to perform this operation'], 403),
            self::DO.'/vpcs*' => Http::response(['vpcs' => []]),
            self::DO.'/databases*' => Http::response(['databases' => []]),
            self::DO.'/spaces/keys*' => Http::response(['keys' => []]),
        ]);

        $result = Providers::find('digitalocean')->check('scoped');

        $this->assertSame(CheckStatus::MissingRights, $result->status);
        $this->assertSame(['kubernetes', 'registry'], $result->missing);
        $this->assertSame(
            'DigitalOcean answered 403 for kubernetes: You are not authorized to perform this operation; registry: You are not authorized to perform this operation.',
            $result->message,
        );
    }

    public function test_digitalocean_names_the_missing_database_and_spaces_key_rights_and_still_accepts_the_key(): void
    {
        Http::fake([
            self::DO.'/account' => Http::response(['account' => ['email' => 'me@example.com']]),
            self::DO.'/kubernetes/clusters*' => Http::response(['kubernetes_clusters' => []]),
            self::DO.'/registry' => Http::response(['id' => 'not_found', 'message' => 'no registry'], 404),
            self::DO.'/vpcs*' => Http::response(['vpcs' => []]),
            self::DO.'/databases*' => Http::response(['id' => 'forbidden', 'message' => 'You are not authorized to perform this operation'], 403),
            self::DO.'/spaces/keys*' => Http::response(['id' => 'forbidden', 'message' => 'You are not authorized to perform this operation'], 403),
        ]);
        $guide = Providers::find('digitalocean');

        $result = $guide->check('no-database');

        $this->assertSame(CheckStatus::MissingRights, $result->status);
        $this->assertSame(['database', 'spaces_key'], $result->missing);
        $this->assertSame('DigitalOcean answered 403 for database: You are not authorized to perform this operation; spaces_key: You are not authorized to perform this operation.', $result->message);
        $this->assertTrue($guide->accepts($result));
        $this->assertSame([
            'database: all rights (create, read, update, delete, view_credentials). Needed for managed Postgres and managed Valkey.',
            'spaces_key: all rights (create_credentials, read, update, delete). Needed for a Spaces bucket.',
        ], $guide->labelsOf($result->missing));
        Http::assertSent(fn (Request $request) => $request->url() === self::DO.'/spaces/keys?per_page=1');
    }

    public function test_hetzner_ok_invalid_and_forbidden(): void
    {
        $url = 'https://api.hetzner.cloud/v1/servers*';
        $guide = Providers::find('hetzner');

        Http::fake([$url => Http::sequence()
            ->push(['servers' => []])
            ->push(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401)
            ->push(['error' => ['code' => 'forbidden', 'message' => 'insufficient permissions']], 403)]);

        $this->assertSame(CheckStatus::Ok, $guide->check('good')->status);

        $invalid = $guide->check('bad');
        $this->assertSame(CheckStatus::Invalid, $invalid->status);
        $this->assertSame('unable to authenticate', $invalid->message);

        $this->assertSame(['read_write'], $guide->check('limited')->missing);
    }

    public function test_cloudflare_ok_lists_the_zones(): void
    {
        $this->fakeCloudflare(zones: [['id' => 'z1', 'name' => 'example.com'], ['id' => 'z2', 'name' => 'example.org']]);

        $result = Providers::find('cloudflare')->check('cf-token');

        $this->assertSame(CheckStatus::Ok, $result->status);
        $this->assertSame('The token works. It sees these zones: example.com, example.org.', $result->message);
        $this->assertSame([['id' => 'z1', 'name' => 'example.com'], ['id' => 'z2', 'name' => 'example.org']], $result->details['zones']);
    }

    public function test_cloudflare_invalid_uses_the_message_of_cloudflare(): void
    {
        Http::fake([self::CF.'/user/tokens/verify' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 401)]);

        $result = Providers::find('cloudflare')->check('bad');

        $this->assertSame(CheckStatus::Invalid, $result->status);
        $this->assertSame('Invalid API Token', $result->message);
    }

    public function test_cloudflare_without_zone_read_is_missing_that_right(): void
    {
        Http::fake([
            self::CF.'/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
            self::CF.'/zones*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']]], 403),
        ]);

        $result = Providers::find('cloudflare')->check('t');

        $this->assertSame(CheckStatus::MissingRights, $result->status);
        $this->assertSame(['zone_read'], $result->missing);
    }

    public function test_cloudflare_with_no_zones_asks_for_zone_resources(): void
    {
        $this->fakeCloudflare(zones: []);

        $this->assertSame(['zones'], Providers::find('cloudflare')->check('t')->missing);
    }

    public function test_cloudflare_without_dns_rights_is_missing_dns_edit(): void
    {
        $this->fakeCloudflare(zones: [['id' => 'z1', 'name' => 'example.com']], dnsAllowed: false);

        $result = Providers::find('cloudflare')->check('t');

        $this->assertSame(['dns_edit'], $result->missing);
        $this->assertSame([['id' => 'z1', 'name' => 'example.com']], $result->details['zones']);
    }

    public function test_aws_ok_when_sts_and_ecr_accept_the_key(): void
    {
        Http::fake([
            'https://sts.amazonaws.com/' => Http::response(['GetCallerIdentityResponse' => ['GetCallerIdentityResult' => ['Arn' => 'arn:aws:iam::123456789012:user/opencloud']]]),
            'https://api.ecr.us-east-1.amazonaws.com/' => Http::response(['authorizationData' => []]),
        ]);

        $result = Providers::find('aws')->check('AKIAEXAMPLE:secretvalue');

        $this->assertSame(CheckStatus::Ok, $result->status);
        $this->assertStringContainsString('arn:aws:iam::123456789012:user/opencloud', $result->message);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://sts.amazonaws.com/'
            && $request->body() === 'Action=GetCallerIdentity&Version=2011-06-15'
            && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/')
            && ! str_contains($request->header('Authorization')[0], 'secretvalue'));
    }

    public function test_aws_invalid_uses_the_message_of_aws(): void
    {
        Http::fake(['https://sts.amazonaws.com/' => Http::response(['Error' => ['Code' => 'InvalidClientTokenId', 'Message' => 'The security token included in the request is invalid.']], 403)]);

        $result = Providers::find('aws')->check('AKIAFAKE:secret');

        $this->assertSame(CheckStatus::Invalid, $result->status);
        $this->assertSame('The security token included in the request is invalid.', $result->message);
        Http::assertSentCount(1);
    }

    public function test_aws_without_ecr_rights_is_missing_ecr_push(): void
    {
        Http::fake([
            'https://sts.amazonaws.com/' => Http::response(['GetCallerIdentityResponse' => ['GetCallerIdentityResult' => ['Arn' => 'arn:aws:iam::1:user/x']]]),
            'https://api.ecr.us-east-1.amazonaws.com/' => Http::response(['__type' => 'AccessDeniedException', 'message' => 'User is not authorized to perform: ecr:GetAuthorizationToken'], 400),
        ]);

        $result = Providers::find('aws')->check('AKIAEXAMPLE:secret');

        $this->assertSame(CheckStatus::MissingRights, $result->status);
        $this->assertSame(['ecr_push'], $result->missing);
        $this->assertStringContainsString('User is not authorized to perform: ecr:GetAuthorizationToken', $result->message);
    }

    public function test_aws_refuses_a_paste_that_is_not_id_and_secret(): void
    {
        Http::fake();

        $result = Providers::find('aws')->check('only-one-value');

        $this->assertSame(CheckStatus::Invalid, $result->status);
        Http::assertNothingSent();
    }

    /** @param  list<array{id: string, name: string}>  $zones */
    private function fakeCloudflare(array $zones, bool $dnsAllowed = true): void
    {
        Http::fake([
            self::CF.'/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
            self::CF.'/zones/*/dns_records*' => $dnsAllowed
                ? Http::response(['success' => true, 'result' => []])
                : Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403),
            self::CF.'/zones*' => Http::response(['success' => true, 'result' => $zones, 'result_info' => ['total_pages' => 1]]),
        ]);
    }
}
