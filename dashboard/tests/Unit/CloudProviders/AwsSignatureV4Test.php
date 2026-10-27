<?php

namespace Tests\Unit\CloudProviders;

use App\Domains\CloudProviders\AwsSignatureV4;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AwsSignatureV4Test extends TestCase
{
    /** The get-vanilla case of the AWS Signature Version 4 test suite. */
    public function test_it_matches_the_published_aws_test_vector(): void
    {
        $headers = AwsSignatureV4::sign(
            'GET',
            'example.amazonaws.com',
            '/',
            '',
            'us-east-1',
            'service',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            new DateTimeImmutable('2015-08-30 12:36:00 UTC'),
        );

        $this->assertSame([
            'X-Amz-Date' => '20150830T123600Z',
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
        ], $headers);
    }
}
