<?php

namespace App\Datastores;

/**
 * How an app reaches one attached resource, without env names. Exactly three classes implement it, one per family:
 * SqlConnection, KeyValueConnection, and BucketConnection. A provisioner gives one, and an env profile names it.
 */
interface Connection {}
