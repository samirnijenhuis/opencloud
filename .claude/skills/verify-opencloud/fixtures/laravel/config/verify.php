<?php

// The app caches its config when it starts, so the env values are read here and not in the controller.
return [
    'marker' => env('VERIFY_MARKER'),
    'secret_sha' => env('VERIFY_SECRET') === null ? null : sha1(env('VERIFY_SECRET')),
    'database' => env('DB_CONNECTION') !== null,
    // Opencloud sets one <NAME>_DB_URL value for each database that is not the default.
    'database_urls' => collect(getenv())->filter(fn (string $value, string $key) => str_ends_with($key, '_DB_URL'))->sortKeys()->all(),
];
