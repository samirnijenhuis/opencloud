<?php

namespace App\Datastores;

use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * What OpenTofu creates at DigitalOcean for one managed attachment, and how the outputs become the address on the
 * attachment row. ManagedResource runs the same lifecycle around each one, on the builds queue with the lock of the
 * datastore.
 */
interface ManagedStack
{
    /** The version on the datastore row. */
    public function version(): string;

    /**
     * The attachment columns that attach() sets before the apply, for example the names that OpenTofu gets.
     *
     * @return array<string, mixed>
     */
    public function names(Environment $environment, ?string $name): array;

    /**
     * Runs the apply and gives the attachment columns that hold the address. A second run converges.
     *
     * @return array<string, mixed>
     */
    public function apply(DatastoreAttachment $attachment): array;

    /** Destroys everything that apply() created, with the data. */
    public function destroy(DatastoreAttachment $attachment): void;

    public function connection(DatastoreAttachment $attachment): ?Connection;
}
