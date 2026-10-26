<?php

namespace App\CloudProviders;

use InvalidArgumentException;

/**
 * The one table of provider guides. Add a provider here and nowhere else.
 */
final class Providers
{
    public const AWS_ECR_ACTIONS = [
        'ecr:GetAuthorizationToken',
        'ecr:BatchCheckLayerAvailability',
        'ecr:InitiateLayerUpload',
        'ecr:UploadLayerPart',
        'ecr:CompleteLayerUpload',
        'ecr:PutImage',
        'ecr:CreateRepository',
        'ecr:DescribeRepositories',
    ];

    public const AWS_COMMAND = <<<'SH'
ARN=$(aws iam create-policy --policy-name opencloud-ecr-push --query Policy.Arn --output text --policy-document '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":["ecr:GetAuthorizationToken","ecr:BatchCheckLayerAvailability","ecr:InitiateLayerUpload","ecr:UploadLayerPart","ecr:CompleteLayerUpload","ecr:PutImage","ecr:CreateRepository","ecr:DescribeRepositories"],"Resource":"*"}]}') && aws iam create-user --user-name opencloud --query User.UserName --output text >/dev/null && aws iam attach-user-policy --user-name opencloud --policy-arn "$ARN" && aws iam create-access-key --user-name opencloud --query 'AccessKey.[AccessKeyId,SecretAccessKey]' --output text | tr '\t' ':'
SH;

    /** @var array<string, ProviderGuide>|null */
    private static ?array $guides = null;

    /** @return array<string, ProviderGuide> */
    public static function all(): array
    {
        return self::$guides ??= collect([
            new ProviderGuide(
                key: 'digitalocean',
                label: 'DigitalOcean',
                use: 'Managed Kubernetes clusters, the container registry, managed Postgres and Valkey, and Spaces buckets.',
                url: 'https://cloud.digitalocean.com/account/api/tokens/new',
                steps: [
                    'Open the link and give the token a name, for example opencloud.',
                    'Select Custom Scopes and select the rights below.',
                    'Create the token and copy it. DigitalOcean shows it once.',
                ],
                rights: [
                    new Right('account', 'account: read'),
                    new Right('kubernetes', 'kubernetes: all rights (create, read, update, delete)'),
                    new Right('registry', 'registry: all rights (create, read, update, delete)'),
                    new Right('vpc', 'vpc: read', optional: true),
                    new Right('database', 'database: all rights (create, read, update, delete, view_credentials). Needed for managed Postgres and managed Valkey.', optional: true),
                    new Right('spaces_key', 'spaces_key: all rights (create_credentials, read, update, delete). Needed for a Spaces bucket.', optional: true),
                ],
                command: null,
                pasteHint: 'dop_v1_...',
                checker: Checks\DigitalOceanCheck::class,
            ),
            new ProviderGuide(
                key: 'hetzner',
                label: 'Hetzner',
                use: 'Servers for k3s clusters.',
                url: 'https://console.hetzner.cloud/projects',
                steps: [
                    'Open the link and open the project that will hold the servers.',
                    'Open Security in the side menu and select API tokens.',
                    'Select Generate API token, give it a name, and select Read & Write.',
                    'Copy the token. Hetzner shows it once.',
                ],
                rights: [new Right('read_write', 'Read & Write (opencloud cannot test this right without a change)', optional: true)],
                command: null,
                pasteHint: 'The 64 character token',
                checker: Checks\HetznerCheck::class,
            ),
            new ProviderGuide(
                key: 'aws',
                label: 'AWS',
                use: 'Images for connected EKS clusters go to ECR.',
                url: null,
                steps: [
                    'Sign in to the AWS CLI with an account that can manage IAM.',
                    'Paste the command below in a bash shell.',
                    'Copy the line it prints. It holds the access key ID and the secret, joined by a colon.',
                ],
                rights: [new Right('ecr_push', 'ECR push: '.implode(', ', self::AWS_ECR_ACTIONS))],
                command: self::AWS_COMMAND,
                pasteHint: 'AKIA...:secret',
                checker: Checks\AwsCheck::class,
            ),
            new ProviderGuide(
                key: 'cloudflare',
                label: 'Cloudflare',
                use: 'DNS records and DNS challenges for the zones the token can edit.',
                url: 'https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22zone%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D&accountId=%2A&zoneId=all&name=opencloud',
                steps: [
                    'Open the link. Cloudflare fills in the name and the rights.',
                    'Check the rights below. Limit the zones if you want opencloud to touch fewer domains.',
                    'Create the token and copy it. Cloudflare shows it once.',
                ],
                rights: [
                    new Right('zone_read', 'Zone: Zone: Read'),
                    new Right('dns_edit', 'Zone: DNS: Edit'),
                    new Right('zones', 'Zone Resources: include the zones you want to use'),
                ],
                command: null,
                pasteHint: 'The token that Cloudflare shows',
                checker: Checks\CloudflareCheck::class,
                checkUrl: 'https://developers.cloudflare.com/fundamentals/api/how-to/account-owned-token-template/',
            ),
        ])->keyBy('key')->all();
    }

    public static function find(string $key): ProviderGuide
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown provider [{$key}].");
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
