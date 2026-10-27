<?php

namespace Tests\Domains\CloudProviders;

use App\Domains\CloudProviders\CheckResult;
use App\Domains\CloudProviders\Providers;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_the_registry_lists_the_four_providers(): void
    {
        $this->assertSame(['digitalocean', 'hetzner', 'aws', 'cloudflare'], Providers::keys());
    }

    public function test_each_provider_has_its_literal_key_page(): void
    {
        $urls = array_map(fn ($guide) => $guide->url, Providers::all());

        $this->assertSame([
            'digitalocean' => 'https://cloud.digitalocean.com/account/api/tokens/new',
            'hetzner' => 'https://console.hetzner.cloud/projects',
            'aws' => null,
            'cloudflare' => 'https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22zone%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D&accountId=%2A&zoneId=all&name=opencloud',
        ], $urls);
    }

    public function test_only_aws_has_a_command_and_it_is_the_literal_chain(): void
    {
        $commands = array_filter(array_map(fn ($guide) => $guide->command, Providers::all()));

        $this->assertSame(['aws'], array_keys($commands));
        $this->assertSame(
            'ARN=$(aws iam create-policy --policy-name opencloud-ecr-push --query Policy.Arn --output text --policy-document \'{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":["ecr:GetAuthorizationToken","ecr:BatchCheckLayerAvailability","ecr:InitiateLayerUpload","ecr:UploadLayerPart","ecr:CompleteLayerUpload","ecr:PutImage","ecr:CreateRepository","ecr:DescribeRepositories"],"Resource":"*"}]}\') && aws iam create-user --user-name opencloud --query User.UserName --output text >/dev/null && aws iam attach-user-policy --user-name opencloud --policy-arn "$ARN" && aws iam create-access-key --user-name opencloud --query \'AccessKey.[AccessKeyId,SecretAccessKey]\' --output text | tr \'\t\' \':\'',
            $commands['aws'],
        );
    }

    public function test_the_aws_command_is_one_line_of_valid_bash_and_its_policy_is_json(): void
    {
        $command = Providers::find('aws')->command;

        $this->assertStringNotContainsString("\n", $command);
        exec('bash -n -c '.escapeshellarg($command).' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        preg_match("/--policy-document '(.+?)'\\)/", $command, $match);
        $policy = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(Providers::AWS_ECR_ACTIONS, $policy['Statement'][0]['Action']);
    }

    public function test_a_key_is_kept_when_only_optional_rights_are_missing(): void
    {
        $digitalOcean = Providers::find('digitalocean');

        $this->assertTrue($digitalOcean->accepts(CheckResult::missing('x', ['vpc'])));
        $this->assertFalse($digitalOcean->accepts(CheckResult::missing('x', ['vpc', 'registry'])));
        $this->assertFalse($digitalOcean->accepts(CheckResult::invalid('x')));
    }
}
