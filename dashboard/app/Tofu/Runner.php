<?php

namespace App\Tofu;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The only class that runs OpenTofu. Each stack has a work folder with a copy of its module, the variables in a file
 * of mode 600, and the local state. The provider credentials go into the process env only, never into argv or a file.
 * The state is always encrypted with a key from APP_KEY. Process::fake() swaps tofu in tests.
 */
final class Runner
{
    public const VAR_FILE = 'opencloud.tfvars.json';

    /** Below the 3000 second timeout of the jobs that call it. */
    private const TIMEOUT_SECONDS = 2700;

    private const INIT_TRIES = 3;

    /** @return array<string, mixed> the outputs of the module, name => value */
    public function apply(Stack $stack, Model $logTo): array
    {
        $log = $this->log($stack, $logTo);
        $dir = $this->prepare($stack);

        $this->init($stack, $dir, $log);
        $this->run($stack, $dir, ['apply', '-auto-approve', '-input=false', '-no-color', '-var-file='.self::VAR_FILE], $log);

        // The outputs hold the kubeconfig and the passwords, so they never go into the log.
        $result = $this->process($stack, $dir)->run([config('opencloud.tofu_binary'), 'output', '-json', '-no-color']);

        if ($result->failed()) {
            throw new RuntimeException('tofu output failed. '.$log->mask(self::errorText($result->errorOutput())));
        }

        return self::parseOutputs($result->output());
    }

    /** Destroys what the stack created and removes its work folder. Resources with destroy = false stay in the cloud. */
    public function destroy(Stack $stack, Model $logTo): void
    {
        $log = $this->log($stack, $logTo);
        $dir = $this->prepare($stack);

        $this->init($stack, $dir, $log);
        $this->run($stack, $dir, ['destroy', '-auto-approve', '-input=false', '-no-color', '-suppress-forget-errors', '-var-file='.self::VAR_FILE], $log);

        File::deleteDirectory($dir);
    }

    public static function workPath(Stack $stack): string
    {
        return rtrim(config('opencloud.data_path'), '/')."/tofu/{$stack->name}";
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    public static function parseOutputs(string $json): array
    {
        $outputs = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn (array $output) => $output['value'], $outputs);
    }

    /** The body of an OpenTofu encryption block. TF_ENCRYPTION carries it, so the passphrase is never on disk. */
    public static function encryption(string $passphrase): string
    {
        return <<<HCL
        key_provider "pbkdf2" "opencloud" {
          passphrase = "{$passphrase}"
        }
        method "aes_gcm" "opencloud" {
          keys = key_provider.pbkdf2.opencloud
        }
        state {
          method   = method.aes_gcm.opencloud
          enforced = true
        }
        plan {
          method   = method.aes_gcm.opencloud
          enforced = true
        }
        HCL;
    }

    /** A new APP_KEY cannot read the old state, like the other stored secrets. */
    public static function passphrase(): string
    {
        return hash_hmac('sha256', 'opencloud-tofu-state', (string) config('app.key'));
    }

    private function prepare(Stack $stack): string
    {
        $module = rtrim(config('opencloud.tofu_modules'), '/')."/{$stack->module}";

        if (! is_dir($module)) {
            throw new RuntimeException("The OpenTofu module {$stack->module} is missing at {$module}.");
        }

        $dir = self::workPath($stack);
        File::ensureDirectoryExists($dir, 0700);

        // A newer release can drop a module file, so the old copies go first.
        File::delete(File::glob("{$dir}/*.tf"));

        foreach (File::files($module, hidden: true) as $file) {
            File::copy($file->getPathname(), "{$dir}/{$file->getFilename()}");
        }

        $vars = "{$dir}/".self::VAR_FILE;
        touch($vars);
        chmod($vars, 0600);
        file_put_contents($vars, json_encode($stack->variables, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }

    /** The provider registry sometimes times out. Init changes no infrastructure, so it can simply run again. */
    private function init(Stack $stack, string $dir, TofuLog $log): void
    {
        retry(self::INIT_TRIES, fn () => $this->run($stack, $dir, ['init', '-input=false', '-no-color'], $log), 5000);
    }

    /** @param  list<string>  $args */
    private function run(Stack $stack, string $dir, array $args, TofuLog $log): void
    {
        $log->write('$ tofu '.implode(' ', $args));

        $result = $this->process($stack, $dir)
            ->start([config('opencloud.tofu_binary'), ...$args], fn (string $type, string $output) => $log->chunk($output))
            ->wait();
        $log->flush();

        if ($result->failed()) {
            throw new RuntimeException("tofu {$args[0]} failed. ".$log->mask(self::errorText($result->errorOutput() ?: $result->output())));
        }
    }

    private function process(Stack $stack, string $dir): PendingProcess
    {
        $plugins = rtrim(config('opencloud.data_path'), '/').'/tofu/plugins';
        File::ensureDirectoryExists($plugins, 0700);

        return Process::path($dir)->timeout(self::TIMEOUT_SECONDS)->env([
            ...$stack->credentials,
            'TF_ENCRYPTION' => self::encryption(self::passphrase()),
            'TF_IN_AUTOMATION' => '1',
            'TF_INPUT' => '0',
            'TF_PLUGIN_CACHE_DIR' => $plugins,
        ]);
    }

    private function log(Stack $stack, Model $logTo): TofuLog
    {
        return new TofuLog($logTo, [...array_values($stack->credentials), self::passphrase()]);
    }

    /** The first error block of the output, on one line, for the error field of the record. The log has the rest. */
    private static function errorText(string $output): string
    {
        $lines = array_map(fn (string $line) => trim(preg_replace('/^\s*[│╷╵]/u', '', $line)), preg_split('/\R/', $output));
        $lines = array_values(array_filter($lines, fn (string $line) => $line !== ''));
        $start = max(0, count($lines) - 4);

        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Error:')) {
                $start = $i;
                break;
            }
        }

        return implode(' ', array_slice($lines, $start, 4));
    }
}
