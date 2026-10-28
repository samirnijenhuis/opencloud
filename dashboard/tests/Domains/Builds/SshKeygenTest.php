<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\SshKeygen;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** These tests run the real ssh-keygen, because a fake cannot show that the key is a real ed25519 key pair. */
class SshKeygenTest extends TestCase
{
    /** A test key that nothing trusts, without its last newline, as a trimmed form field gives it. ssh-keygen -y gives FIXED_PUBLIC_KEY for it. */
    private const FIXED_PRIVATE_KEY = <<<'KEY'
        -----BEGIN OPENSSH PRIVATE KEY-----
        b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
        QyNTUxOQAAACDDRGJyvXk6skzRKZOCOyK174odhCrFoexyPISEc5qiuwAAAJimHaZwph2m
        cAAAAAtzc2gtZWQyNTUxOQAAACDDRGJyvXk6skzRKZOCOyK174odhCrFoexyPISEc5qiuw
        AAAEAgu340COccDKkSGnLqWc7f0z1TDZSCMKotF6RIHaCztMNEYnK9eTqyTNEpk4I7IrXv
        ih2EKsWh7HI8hIRzmqK7AAAAEm9wZW5jbG91ZCB0ZXN0IGtleQECAw==
        -----END OPENSSH PRIVATE KEY-----
        KEY;

    private const FIXED_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMNEYnK9eTqyTNEpk4I7IrXvih2EKsWh7HI8hIRzmqK7 opencloud test key';

    /** A test key with the passphrase "a passphrase". */
    private const PASSPHRASE_PRIVATE_KEY = <<<'KEY'
        -----BEGIN OPENSSH PRIVATE KEY-----
        b3BlbnNzaC1rZXktdjEAAAAACmFlczI1Ni1jdHIAAAAGYmNyeXB0AAAAGAAAABBRYK9Jum
        bFDlJhSVF8lsXtAAAAGAAAAAEAAAAzAAAAC3NzaC1lZDI1NTE5AAAAIJNSn/cYjdCSQ0SW
        7QPgaUfq7vhnpsszQmKyJ/Gc465uAAAAsCSGmiE98EXCJUzM+qnk9iCFGBnMLY2abP4pf1
        fC0+lWiV+akRKg2QItntrzQsVwdVPwMqwb+vAvOoZl78AcEO8Nr9ojaMHdoS0n4R3SRKx1
        tf1kwHgow7CHkRE/R7ncUmeZENcJSLe05GncRdrJOxQzZzhqdUOuGErFvyaASnSQSfS3ua
        4/S7qa3ROxmZTxj/ZkrK8+DXNLeWH5MPWa0ldawckoiDkOfXskqHlQVRM2
        -----END OPENSSH PRIVATE KEY-----
        KEY;

    public function test_it_reads_the_public_key_of_a_pasted_private_key_and_gives_back_the_last_newline_and_leaves_no_key_file(): void
    {
        $before = self::ownKeyFiles();

        $key = app(SshKeygen::class)->keyPairOf(self::FIXED_PRIVATE_KEY);

        $this->assertSame(['private' => self::FIXED_PRIVATE_KEY."\n", 'public' => self::FIXED_PUBLIC_KEY], $key);
        $this->assertSame($before, self::ownKeyFiles());
    }

    public function test_a_key_with_a_passphrase_and_a_public_key_give_the_message_and_leave_no_key_file(): void
    {
        $before = self::ownKeyFiles();
        $messages = [];

        foreach ([self::PASSPHRASE_PRIVATE_KEY, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMNEYnK9eTqyTNEpk4I7IrXvih2EKsWh7HI8hIRzmqK7'] as $privateKey) {
            try {
                app(SshKeygen::class)->keyPairOf($privateKey);
                $this->fail('No exception.');
            } catch (ValidationException $e) {
                $messages[] = $e->errors();
            }
        }

        $this->assertSame(array_fill(0, 2, ['deploy_private_key' => ['Paste an OpenSSH private key without a passphrase.']]), $messages);
        $this->assertSame($before, self::ownKeyFiles());
    }

    public function test_it_makes_a_real_ed25519_key_pair_and_leaves_no_key_files(): void
    {
        $before = self::keyFolders();

        $key = app(SshKeygen::class)->keyPair('opencloud shop');

        [$type, $blob, $comment] = explode(' ', $key['public'], 3);
        $wire = base64_decode($blob, true);
        $this->assertSame(['ssh-ed25519', 'opencloud shop'], [$type, $comment]);
        // The OpenSSH wire format: a 4-byte length and the key type, then a 4-byte length and the 32-byte key.
        $this->assertSame([51, 11, 'ssh-ed25519', 32], [strlen($wire), unpack('N', $wire)[1], substr($wire, 4, 11), unpack('N', substr($wire, 15, 4))[1]]);
        $this->assertStringStartsWith("-----BEGIN OPENSSH PRIVATE KEY-----\n", $key['private']);
        $this->assertStringEndsWith("-----END OPENSSH PRIVATE KEY-----\n", $key['private']);
        $this->assertSame("ssh-ed25519 {$blob}", self::publicKeyOf($key['private']));
        $this->assertSame($before, self::keyFolders());
    }

    public function test_a_failed_ssh_keygen_leaves_no_key_files(): void
    {
        $before = self::keyFolders();
        Process::fake(['*' => Process::result(errorOutput: 'ssh-keygen: not found', exitCode: 127)]);

        try {
            app(SshKeygen::class)->keyPair('opencloud shop');
            $this->fail('No exception.');
        } catch (ProcessFailedException $e) {
            $this->assertSame(127, $e->result->exitCode());
        }

        $this->assertSame($before, self::keyFolders());
    }

    /** @return list<string> */
    private static function ownKeyFiles(): array
    {
        return glob(sys_get_temp_dir().'/opencloud-own-key-*');
    }

    /** @return list<string> */
    private static function keyFolders(): array
    {
        return glob(sys_get_temp_dir().'/opencloud-ssh-keygen-*');
    }

    /** ssh-keygen -y derives the public key from the private key, so a match proves the two keys are one pair. */
    private static function publicKeyOf(string $privateKey): string
    {
        $file = tempnam(sys_get_temp_dir(), 'opencloud-test-key-');
        file_put_contents($file, $privateKey);

        try {
            $fields = explode(' ', trim(Process::run(['ssh-keygen', '-y', '-f', $file])->throw()->output()));
        } finally {
            unlink($file);
        }

        return "{$fields[0]} {$fields[1]}";
    }
}
