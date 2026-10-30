<?php

// matrix.py local runs this. It asks the dashboard code of this checkout what a deploy of one cell gets: the Dockerfile
// that Builder::prepare() writes into the app folder, the key of the build values, the port, the env values of the
// preset and of each attachment, and the datastore versions. So a local run builds and starts the app the way the
// cluster does. A fixed key would let BuildKit reuse a layer that read the build values of another cell.

use App\Domains\Builds\Builder;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\SqlConnection;
use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\DatastoreManifests;
use Illuminate\Contracts\Console\Kernel;

$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

require "{$input['dashboard']}/vendor/autoload.php";
$app = require "{$input['dashboard']}/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$type = ApplicationType::from($input['type']);
$port = $type->choosesPort() ? ($input['port'] ?? $type->defaultPort()) : $type->defaultPort();
$dockerfile = (new ReflectionMethod(Builder::class, 'prepare'))
    ->invoke(new Builder, $type, $input['dir'], array_keys($input['build_env']), $input['build_command']);

$env = $type->systemEnv($port, null, []);

foreach ($input['attachments'] as $a) {
    $connection = $a['kind'] === 'valkey'
        ? new KeyValueConnection($a['host'], $a['port'], $a['password'], false)
        : new SqlConnection(DatastoreKind::from($a['kind']), $a['host'], $a['port'], $a['database'], $a['username'], $a['password'], $a['name'], $a['default']);

    foreach ($type->envProfiles() as $profile) {
        foreach ($connection->envFor($profile) as $key => $value) {
            $env[$key] = $value->value;
        }
    }
}

echo json_encode([
    'dockerfile' => $dockerfile,
    'build_env_key' => Builder::buildEnvKey($input['build_env']),
    'port' => $port,
    'env' => [...$env, ...$type->generatedSecrets()],
    'versions' => DatastoreManifests::VERSIONS,
]);
