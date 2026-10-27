<?php

// matrix.py local runs this. It asks the dashboard code of this checkout what a deploy of one cell gets: the Dockerfile
// that Builder::prepare() writes into the app folder, the port, the env values of the preset and of each attachment,
// and the datastore versions. So a local run builds and starts the app the way the cluster does.

use App\Build\Builder;
use App\Datastores\KeyValueConnection;
use App\Datastores\SqlConnection;
use App\Enums\ApplicationType;
use App\Enums\DatastoreKind;
use App\Kube\Manifests\DatastoreManifests;
use App\Presets\EnvProfileKind;
use Illuminate\Contracts\Console\Kernel;

$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

require "{$input['dashboard']}/vendor/autoload.php";
$app = require "{$input['dashboard']}/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$type = ApplicationType::from($input['type']);
$profile = EnvProfileKind::from($input['env_profile'])->profile();
$port = $type->choosesPort() ? ($input['port'] ?? $type->defaultPort()) : $type->defaultPort();
$dockerfile = (new ReflectionMethod(Builder::class, 'prepare'))
    ->invoke(new Builder, $type, $input['dir'], $input['build_secrets'], $input['build_command']);

$env = $type->systemEnv($port, null);

foreach ($input['attachments'] as $a) {
    $connection = $a['kind'] === 'valkey'
        ? new KeyValueConnection($a['host'], $a['port'], $a['password'], false)
        : new SqlConnection(DatastoreKind::from($a['kind']), $a['host'], $a['port'], $a['database'], $a['username'], $a['password'], $a['name'], $a['default']);

    foreach ($connection->envFor($profile) as $key => $value) {
        $env[$key] = $value->value;
    }
}

echo json_encode([
    'dockerfile' => $dockerfile,
    'port' => $port,
    'env' => [...$env, ...$type->generatedSecrets()],
    'versions' => DatastoreManifests::VERSIONS,
]);
