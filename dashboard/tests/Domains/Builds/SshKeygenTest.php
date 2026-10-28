<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\SshKeygen;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** These tests run the real ssh-keygen, because a fake cannot show that the key is a real ed25519 key pair. */
class SshKeygenTest extends TestCase
{
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
