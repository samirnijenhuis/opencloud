<?php

namespace App\Datastores;

/**
 * What opencloud does with one kind from one source, beyond attach and detach. The backend asks this value and does
 * not test a kind, a source, or an engine class. Whether an environment can have several of a kind follows from the
 * family of the kind, whatever the source.
 */
final readonly class Capabilities
{
    /** The dump and the restore that opencloud runs itself, or null. */
    public ?DatabaseDumps $dumps;

    /** Why opencloud does not back it up, when $dumps is null. A manual backup or restore shows it. */
    public ?string $noDumps;

    /**
     * @param  DatabaseDumps|string  $dumps  the dumps, or why there are none: there is no database, or a provider backs
     *                                       it up itself
     * @param  bool  $copiesBuckets  opencloud copies every bucket of the server to the backup target each night, and an
     *                               install restore copies them back
     * @param  string|null  $notOffered  why the dashboard does not offer it, or null when it does. The code paths still
     *                                   work, so existing rows keep running.
     */
    public function __construct(
        DatabaseDumps|string $dumps,
        public bool $copiesBuckets = false,
        public ?string $notOffered = null,
    ) {
        [$this->dumps, $this->noDumps] = is_string($dumps) ? [null, $dumps] : [$dumps, null];
    }
}
