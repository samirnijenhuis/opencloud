<?php

namespace App\Domains\Builds;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Factory;
use Illuminate\Support\Str;

/**
 * Makes the deploy key of an application with ssh-keygen. The key files live only during the call.
 */
final class SshKeygen
{
    public function __construct(private Factory $process, private Filesystem $files) {}

    /**
     * @return array{private: string, public: string} an ed25519 key pair in OpenSSH format, without a passphrase
     *
     * @throws ProcessFailedException when ssh-keygen fails
     */
    public function keyPair(string $comment): array
    {
        // ssh-keygen asks before it overwrites a file, so the key goes into a new empty folder.
        $dir = sys_get_temp_dir().'/opencloud-ssh-keygen-'.Str::lower(Str::random(10));
        $this->files->makeDirectory($dir, 0700);

        try {
            $this->process->run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', "{$dir}/key"])->throw();

            return ['private' => $this->files->get("{$dir}/key"), 'public' => trim($this->files->get("{$dir}/key.pub"))];
        } finally {
            $this->files->deleteDirectory($dir);
        }
    }
}
