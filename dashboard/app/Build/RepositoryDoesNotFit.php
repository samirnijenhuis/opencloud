<?php

namespace App\Build;

use RuntimeException;

/** The repository does not fit the preset of the application. A preset cannot change after creation, so the message says what to do. */
final class RepositoryDoesNotFit extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("{$reason} To use another preset, create a new application with the same repository.");
    }
}
