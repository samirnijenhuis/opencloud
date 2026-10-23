<?php

namespace App\Tofu;

use App\Support\SecretMasker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Appends masked, time-stamped OpenTofu output to the tofu_log column of a cluster or a datastore. The UI shows it.
 */
final class TofuLog
{
    private string $partial = '';

    private readonly SecretMasker $masker;

    /** @param  iterable<string>  $secrets */
    public function __construct(private readonly Model $model, iterable $secrets)
    {
        $this->masker = new SecretMasker($secrets);
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

        DB::update("update {$this->model->getTable()} set tofu_log = coalesce(tofu_log, '') || ? where id = ?", [$block, $this->model->getKey()]);
    }

    /** Takes raw process output, which can end in the middle of a line. Masking works on whole lines. */
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

    public function mask(string $text): string
    {
        return $this->masker->mask($text);
    }
}
