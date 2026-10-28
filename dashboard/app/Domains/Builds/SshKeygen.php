<?php

namespace App\Domains\Builds;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Makes the deploy key of an application with ssh-keygen, or reads the public key of a private key that the user gives.
 * The key files live only during the call.
 */
final class SshKeygen
{
    public function __construct(private Factory $process, private Filesystem $files) {}

    /**
     * ssh-keygen and ssh refuse a key file without its last newline, and Laravel trims each string of a form.
     *
     * @return array{private: string, public: string} the private key with its last newline, and its public key with the comment of the key
     *
     * @throws ValidationException on deploy_private_key when ssh-keygen cannot read the key, or the key has a passphrase
     */
    public function keyPairOf(string $privateKey): array
    {
        $private = trim($privateKey)."\n";
        // tempnam() creates the file with mode 0600, and ssh-keygen refuses a private key file that others can read.
        $file = tempnam(sys_get_temp_dir(), 'opencloud-own-key-');

        try {
            file_put_contents($file, $private);
            $result = $this->process->run(['ssh-keygen', '-y', '-P', '', '-f', $file]);
        } finally {
            unlink($file);
        }

        if ($result->failed()) {
            throw ValidationException::withMessages(['deploy_private_key' => 'Paste an OpenSSH private key without a passphrase.']);
        }

        return ['private' => $private, 'public' => trim($result->output())];
    }

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
