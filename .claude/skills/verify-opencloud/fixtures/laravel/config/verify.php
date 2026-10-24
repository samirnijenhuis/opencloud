<?php

// The app caches its config when it starts, so the env values are read here and not in the controller.
return [
    'marker' => env('VERIFY_MARKER'),
    'secret_sha' => env('VERIFY_SECRET') === null ? null : sha1(env('VERIFY_SECRET')),
    'database' => env('DB_CONNECTION') !== null,
];
