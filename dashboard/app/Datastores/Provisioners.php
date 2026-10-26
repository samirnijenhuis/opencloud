<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Models\Cluster;
use App\Models\Datastore;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use LogicException;

/**
 * The registry and the capability table of the resources that opencloud can attach, keyed by kind and source. Every
 * list of kinds or sources in the backend comes from find(), and nothing else creates a provisioner.
 */
final class Provisioners
{
    /**
     * Every kind and source with a provisioner, in enum order.
     *
     * @return list<array{kind: DatastoreKind, source: DatastoreSource, provisioner: Provisioner}>
     */
    public static function table(): array
    {
        $rows = [];

        foreach (DatastoreSource::cases() as $source) {
            foreach (DatastoreKind::cases() as $kind) {
                $provisioner = self::find($kind, $source);

                if ($provisioner !== null) {
                    $rows[] = ['kind' => $kind, 'source' => $source, 'provisioner' => $provisioner];
                }
            }
        }

        return $rows;
    }

    /**
     * For a new attachment.
     *
     * @throws InvalidArgumentException when opencloud cannot attach the kind from the source
     */
    public static function for(DatastoreKind $kind, DatastoreSource $source): Provisioner
    {
        return self::find($kind, $source) ?? throw new InvalidArgumentException(self::unsupported($source));
    }

    /** For a stored datastore. A row exists only because find() gave a provisioner for its kind and source. */
    public static function of(Datastore $datastore): Provisioner
    {
        return self::find($datastore->kind, $datastore->source)
            ?? throw new LogicException("No provisioner serves {$datastore->kind->value} from the {$datastore->source->value} source.");
    }

    /**
     * The kinds that the dashboard offers from the source to an environment on the cluster.
     *
     * @return list<DatastoreKind>
     */
    public static function offered(DatastoreSource $source, Cluster $cluster): array
    {
        $offered = array_filter(self::table(), fn (array $row) => $row['source'] === $source
            && $row['provisioner']->capabilities()->notOffered === null
            && $row['provisioner']->refusalOn($cluster) === null);

        return array_values(array_column($offered, 'kind'));
    }

    /** Why the attach form refuses the kind from the source, or null. */
    public static function notOffered(DatastoreKind $kind, DatastoreSource $source): ?string
    {
        $provisioner = self::find($kind, $source);

        return $provisioner === null ? self::unsupported($source) : $provisioner->capabilities()->notOffered;
    }

    /**
     * The stored datastores whose capabilities pass the filter.
     *
     * @param  Closure(Capabilities): bool  $filter
     * @return Builder<Datastore>
     */
    public static function datastores(Closure $filter): Builder
    {
        $rows = array_filter(self::table(), fn (array $row) => $filter($row['provisioner']->capabilities()));

        return Datastore::query()->where(function (Builder $query) use ($rows) {
            // Without a matching row the query must select nothing, not every datastore.
            $query->whereRaw('1 = 0');

            foreach ($rows as $row) {
                $query->orWhere(fn (Builder $pair) => $pair->where('kind', $row['kind'])->where('source', $row['source']));
            }
        });
    }

    /** The local arm has no default, so a new kind does not run until it has a local provisioner. */
    private static function find(DatastoreKind $kind, DatastoreSource $source): ?Provisioner
    {
        return match ($source) {
            DatastoreSource::Standard => match ($kind) {
                DatastoreKind::Mysql => new LocalMysql,
                DatastoreKind::Postgres => new LocalPostgres,
                DatastoreKind::Valkey => new LocalValkey,
                DatastoreKind::Rustfs => new LocalRustfs,
            },
            DatastoreSource::Managed => ManagedResources::find($kind),
            DatastoreSource::External => ExternalResources::find($kind),
        };
    }

    private static function unsupported(DatastoreSource $source): string
    {
        return match ($source) {
            DatastoreSource::Managed => ManagedResources::unsupported(),
            DatastoreSource::External => ExternalResources::unsupported(),
        };
    }
}
