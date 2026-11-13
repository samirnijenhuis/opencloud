<?php

namespace App\Domains\Builds\Exceptions;

use RuntimeException;

/** The repository answers, but the branch does not exist. */
final class BranchNotFound extends RuntimeException {}
