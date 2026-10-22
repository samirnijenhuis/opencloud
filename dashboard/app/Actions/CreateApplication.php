<?php

namespace App\Actions;

use App\Build\Git;
use App\Enums\ApplicationType;
use App\Enums\DatastoreKind;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateApplication
{
    public function __construct(
        private SetEnvVar $setEnvVar,
        private AddDomain $addDomain,
        private StartDeployment $startDeployment,
        private AttachDatastore $attachDatastore,
    ) {}

    /**
     * Creates the application, its protected production environment, the preview template, the env values,
     * the database, and the domains. Then it starts the first deployment of production.
     * A Laravel app gets a MySQL database unless `database` is `none`. Other apps get one only when `database` asks.
     *
     * @param array{
     *     name: string,
     *     repository: string,
     *     cluster_id: int,
     *     type?: ApplicationType|string,
     *     port?: int,
     *     branch?: string,
     *     env?: list<array{key: string, value: string, is_secret?: bool, available_at?: EnvVarAvailability|string}>,
     *     domains?: list<string>,
     *     database?: 'mysql'|'postgres'|'none',
     * } $data
     */
    public function handle(User $by, array $data): Application
    {
        $type = $data['type'] ?? ApplicationType::Laravel;
        $type = $type instanceof ApplicationType ? $type : ApplicationType::from($type);
        $branch = $data['branch'] ?? 'main';
        $env = $data['env'] ?? [];
        $database = $data['database'] ?? ($type === ApplicationType::Laravel ? 'mysql' : 'none');

        // A wrong repository or branch fails here, before any record exists.
        $commitSha = Git::head((new Application(['repository' => $data['repository']]))->cloneUrl(), $branch);

        [$application, $production] = DB::transaction(function () use ($by, $data, $type, $branch, $env, $database) {
            $application = Application::create([
                'name' => $data['name'],
                'repository' => $data['repository'],
                'cluster_id' => $data['cluster_id'],
                'type' => $type,
                'port' => $data['port'] ?? 8080,
            ]);

            $settings = $type === ApplicationType::Laravel
                ? ['deploy_commands' => ['php artisan migrate --force']]
                : ['health_path' => '/'];

            $production = $application->environments()->create([
                'name' => 'production',
                'kind' => EnvironmentKind::Permanent,
                'is_protected' => true,
                'branch' => $branch,
                ...$settings,
            ]);

            $application->environments()->create([
                'name' => 'preview-template',
                'kind' => EnvironmentKind::PreviewTemplate,
                'is_protected' => false,
                'auto_deploy' => false,
                ...$settings,
            ]);

            AuditEvent::record($by, 'application.created', $application, ['repository' => $application->repository, 'type' => $type->value]);

            if ($type === ApplicationType::Laravel && ! in_array('APP_KEY', array_column($env, 'key'), true)) {
                $this->setEnvVar->handle($production, $by, 'APP_KEY', 'base64:'.base64_encode(random_bytes(32)), true, EnvVarAvailability::Runtime);
            }

            foreach ($env as $value) {
                $availableAt = $value['available_at'] ?? EnvVarAvailability::Runtime;

                $this->setEnvVar->handle(
                    $production,
                    $by,
                    $value['key'],
                    $value['value'],
                    $value['is_secret'] ?? false,
                    $availableAt instanceof EnvVarAvailability ? $availableAt : EnvVarAvailability::from($availableAt),
                );
            }

            if ($database !== 'none') {
                $this->attachDatastore->handle($production, $by, DatastoreKind::from($database));
            }

            foreach ($data['domains'] ?? [] as $hostname) {
                $this->addDomain->handle($production, $by, $hostname);
            }

            return [$application, $production];
        });

        $this->startDeployment->handle($production, $by, $commitSha);

        return $application;
    }
}
