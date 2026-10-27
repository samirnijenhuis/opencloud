<?php

namespace App\Domains\Restore;

use App\Domains\Restore\Enums\RestoreStep;
use App\Domains\Restore\Enums\StepStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The progress of a full restore, in restore.json in the data folder. The file outlives the queue workers,
 * so a restore that stopped goes on from the step that did not finish.
 */
final class RestoreState
{
    /**
     * @param  array<int, string>|null  $redeploy  environment id => commit SHA of its live deployment on the lost server
     * @param  array<string, array{status: string, detail: ?string}>  $steps
     */
    private function __construct(
        public readonly Carbon $startedAt,
        private ?array $redeploy,
        private array $steps,
    ) {}

    public static function path(): string
    {
        return config('opencloud.data_path').'/restore.json';
    }

    /** The restore starts when the dashboard database comes back. A dump from after that moment is not from the lost server. */
    public static function begin(): self
    {
        $state = new self(now(), null, []);
        $state->save();

        return $state;
    }

    public static function load(): ?self
    {
        if (! is_file(self::path())) {
            return null;
        }

        $data = json_decode(File::get(self::path()), true, flags: JSON_THROW_ON_ERROR);

        return new self(Carbon::parse($data['started_at']), $data['redeploy'], $data['steps']);
    }

    public function status(RestoreStep $step): StepStatus
    {
        return StepStatus::from($this->steps[$step->value]['status'] ?? StepStatus::PENDING->value);
    }

    public function detail(RestoreStep $step): ?string
    {
        return $this->steps[$step->value]['detail'] ?? null;
    }

    public function mark(RestoreStep $step, StepStatus $status, ?string $detail = null): void
    {
        $this->steps[$step->value] = ['status' => $status->value, 'detail' => $detail];
        $this->save();
    }

    public function finished(): bool
    {
        return collect(RestoreStep::cases())->every(fn (RestoreStep $step) => $this->status($step) === StepStatus::DONE);
    }

    /** @return array<int, string>|null */
    public function redeploy(): ?array
    {
        return $this->redeploy;
    }

    /** @param  array<int, string>  $commits  environment id => commit SHA */
    public function planRedeploy(array $commits): void
    {
        $this->redeploy = $commits;
        $this->save();
    }

    private function save(): void
    {
        $json = json_encode([
            'started_at' => $this->startedAt->toIso8601ZuluString(),
            'redeploy' => $this->redeploy === null ? null : (object) $this->redeploy,
            'steps' => (object) $this->steps,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // A rename replaces the file in one move, so a crash never leaves half a file.
        File::put(self::path().'.tmp', $json);
        File::move(self::path().'.tmp', self::path());
    }
}
