<?php

namespace App\Actions;

use App\Build\Git;
use App\Enums\ApplicationType;
use App\Enums\DatastoreKind;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Environments\DefaultDomain;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\User;
use App\Presets\EnvProfileKind;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateApplication
{
    public function __construct(
        private SetEnvVar $setEnvVar,
        private AddDomain $addDomain,
        private StartDeployment $startDeployment,
        private AttachDatastore $attachDatastore,
        private AddProcess $addProcess,
        private DefaultDomain $defaultDomain,
    ) {}

    /**
     * Creates the application, its protected production environment, the preview template, the env values,
     * the processes, the database, the domains, and the free sslip.io domain. Then it starts the first deployment of production.
     * Each value that `$data` leaves out comes from the preset in `type`.
     *
     * @param array{
     *     name: string,
     *     repository: string,
     *     cluster_id: int,
     *     type?: ApplicationType|string,
     *     env_profile?: EnvProfileKind|string,
     *     port?: int|null,
     *     branch?: string,
     *     env?: list<array{key: string, value: string, is_secret?: bool, available_at?: EnvVarAvailability|string}>,
     *     domains?: list<string>,
     *     database?: 'mysql'|'postgres'|'none',
     *     processes?: list<array{name: string, command: string, instances?: int, exclusive?: bool}>,
     *     settings?: array<string, mixed>,
     * } $data
     */
    public function handle(User $by, array $data): Application
    {
        $type = $data['type'] ?? ApplicationType::Laravel;
        $type = $type instanceof ApplicationType ? $type : ApplicationType::from($type);
        $env = $data['env'] ?? [];
        $database = $data['database'] ?? $type->database();
        $processes = $data['processes'] ?? $type->processes();

        // A wrong repository or branch fails here, before any record exists.
        $cloneUrl = (new Application(['repository' => $data['repository']]))->cloneUrl();
        $branch = ($data['branch'] ?? null) ?: Git::defaultBranch($cloneUrl);
        $commitSha = Git::head($cloneUrl, $branch);

        [$application, $production] = DB::transaction(function () use ($by, $data, $type, $branch, $env, $database, $processes) {
            $application = Application::create([
                'name' => $data['name'],
                'repository' => $data['repository'],
                'cluster_id' => $data['cluster_id'],
                'type' => $type,
                'env_profile' => $data['env_profile'] ?? $type->envProfile(),
                'port' => $type->choosesPort() ? ($data['port'] ?? $type->defaultPort()) : $type->defaultPort(),
            ]);

            $settings = [
                'deploy_commands' => $type->deployCommands(),
                'health_path' => $type->healthPath(),
                ...($data['settings'] ?? []),
            ];

            $production = $application->environments()->create([
                'name' => 'production',
                'kind' => EnvironmentKind::Permanent,
                'is_protected' => true,
                'branch' => $branch,
                ...$settings,
            ]);

            // Previews run as one small instance, so the template takes the commands but not the size.
            $template = $application->environments()->create([
                'name' => 'preview-template',
                'kind' => EnvironmentKind::PreviewTemplate,
                'is_protected' => false,
                'auto_deploy' => false,
                ...Arr::except($settings, ['size', 'min_instances', 'max_instances', 'cpu_target']),
            ]);

            AuditEvent::record($by, 'application.created', $application, ['repository' => $application->repository, 'type' => $type->value]);

            foreach ($processes as $process) {
                $exclusive = $process['exclusive'] ?? false;
                $this->addProcess->handle($production, $by, $process['name'], $process['command'], $process['instances'] ?? 1, $exclusive);
                $this->addProcess->handle($template, $by, $process['name'], $process['command'], 1, $exclusive);
            }

            foreach (array_diff_key($type->generatedSecrets(), array_flip(array_column($env, 'key'))) as $key => $value) {
                $this->setEnvVar->handle($production, $by, $key, $value, true, EnvVarAvailability::Runtime);
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

            $this->defaultDomain->add($production, $by);

            return [$application, $production];
        });

        $this->startDeployment->handle($production, $by, $commitSha);

        return $application;
    }
}
