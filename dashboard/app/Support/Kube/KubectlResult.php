<?php

namespace App\Support\Kube;

final readonly class KubectlResult
{
    public function __construct(
        public int $exitCode,
        public string $output = '',
        public string $error = '',
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        return json_decode($this->output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function throw(): self
    {
        if (! $this->ok()) {
            throw new KubectlException(trim($this->error) ?: trim($this->output) ?: "kubectl exited with code {$this->exitCode}");
        }

        return $this;
    }
}
