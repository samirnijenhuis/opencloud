<?php

namespace App\Deploy;

use App\Models\Deployment;
use App\Support\SecretMasker;
use Illuminate\Support\Facades\DB;

/**
 * Appends masked, time-stamped lines to the log of one deployment. Every writer of a deployment log goes through here.
 */
final class DeploymentLog
{
    private string $partial = '';

    public function __construct(private readonly Deployment $deployment, private readonly SecretMasker $masker) {}

    public static function for(Deployment $deployment): self
    {
        return new self($deployment, SecretMasker::forEnvironment($deployment->environment));
    }

    public function write(string $text): void
    {
        $text = rtrim($text, "\r\n");

        if ($text === '') {
            return;
        }

        $time = now()->toIso8601ZuluString();
        $block = '';

        foreach (preg_split('/\R/', $text) as $line) {
            $block .= "{$time} {$this->masker->mask($line)}\n";
        }

        // Appending in SQL keeps a long build log from being rewritten on every line.
        DB::update("update deployments set log = coalesce(log, '') || ? where id = ?", [$block, $this->deployment->id]);
    }

    /** Takes raw process output, which can end in the middle of a line. */
    public function chunk(string $output): void
    {
        $text = $this->partial.$output;
        $end = strrpos($text, "\n");

        if ($end === false) {
            $this->partial = $text;

            return;
        }

        $this->partial = substr($text, $end + 1);
        $this->write(substr($text, 0, $end));
    }

    public function flush(): void
    {
        $this->write($this->partial);
        $this->partial = '';
    }
}
