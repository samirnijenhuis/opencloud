<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Environments\LogStore;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\KubectlException;
use App\Support\SecretMasker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReadLogs
{
    /** The Logs tab shows the newest lines of the range, up to this count. */
    public const LIMIT = 1000;

    public function __construct(private LogStore $logStore) {}

    /**
     * Asks the Loki of the cluster for the lines of one process in one range. The stream selector always names the
     * namespace of the environment, and the search only filters lines inside that selector. The range starts at the
     * last clear of the logs at the earliest, because Loki drops the cleared lines only some minutes later.
     *
     * @return array{lines: list<array{time: string, pod: string, line: string}>, truncated: bool} the lines from old to new, and whether the range holds older lines
     *
     * @throws InvalidArgumentException when $process is not web or a process of the environment
     * @throws KubectlException when Loki does not answer
     */
    public function execute(Environment $environment, string $process, CarbonInterface $from, CarbonInterface $to, ?string $search = null): array
    {
        $names = $environment->processNames();

        if (! in_array($process, $names, true)) {
            throw new InvalidArgumentException("{$process} is not a process of this environment. Use one of: ".implode(', ', $names).'.');
        }

        if ($environment->logs_cleared_at?->isAfter($from)) {
            $from = $environment->logs_cleared_at;
        }

        // A custom range can end before the clear, and Loki refuses a range that ends before it starts.
        if (! $to->isAfter($from)) {
            return ['lines' => [], 'truncated' => false];
        }

        $query = '{namespace='.LogStore::quote($environment->kubeNamespace()).', process='.LogStore::quote($process).'}';

        if ($search !== null) {
            $query .= ' |~ '.LogStore::quote('(?i)'.preg_quote($search));
        }

        $streams = $this->logStore->lines($environment->application->cluster, $query, $from, $to, self::LIMIT);

        $masker = SecretMasker::forEnvironment($environment);
        $lines = collect($streams)
            ->flatMap(fn (array $stream) => collect($stream['values'])->map(fn (array $value) => [
                'nanoseconds' => (int) $value[0],
                'pod' => $stream['stream']['pod'],
                'line' => $masker->mask($value[1]),
            ]))
            ->sortBy('nanoseconds')
            // Loki searches the unmasked lines. A line that matches only inside a secret would confirm a guessed secret.
            ->when($search !== null, fn ($entries) => $entries->filter(fn (array $entry) => Str::contains($entry['line'], $search, ignoreCase: true)))
            ->map(fn (array $entry) => [
                'time' => CarbonImmutable::createFromTimestampMsUTC(intdiv($entry['nanoseconds'], 1_000_000))->format('Y-m-d\TH:i:s.v\Z'),
                'pod' => $entry['pod'],
                'line' => $entry['line'],
            ])
            ->values()
            ->all();

        // Counted after the filter, so a search that matches only inside secrets also gives no sign.
        return ['lines' => $lines, 'truncated' => count($lines) === self::LIMIT];
    }
}
