<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Enums\RepositoryRead;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\RepositoryProbe;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Builds\SshKeygen;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Environments\Actions\AddProcess;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\DefaultDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateApplication
{
    public function __construct(
        private SetEnvVar $setEnvVar,
        private AttachDatastore $attachDatastore,
        private AddProcess $addProcess,
        private DefaultDomain $defaultDomain,
        private AddDeployKey $addDeployKey,
        private SshKeygen $sshKeygen,
        private RepositoryProbe $repositoryProbe,
    ) {}

    /**
     * Creates the application, its protected production environment, the preview template, and the free sslip.io domain
     * of production. The preset in `type` gives the settings, the processes, the database, and the generated secrets.
     * It starts no deployment. The user sets size, scaling, env values, commands, and domains on the environment, then deploys.
     * With `deploy_key`, it makes the deploy key, and the user adds that key on GitHub before the first deployment.
     * With `deploy_private_key`, it reads the repository with that key.
     *
     * @param array{
     *     name: string,
     *     repository: string,
     *     cluster_id: int,
     *     type?: ApplicationType|string,
     *     port?: int|null,
     *     branch?: string|null,
     *     deploy_key?: bool,
     *     deploy_private_key?: string|null,
     * } $data
     *
     * @throws ValidationException when opencloud cannot read the repository, the branch, or the own key
     */
    public function execute(User $by, array $data): Application
    {
        $type = $data['type'] ?? ApplicationType::LARAVEL;
        $type = $type instanceof ApplicationType ? $type : ApplicationType::from($type);
        $deployKey = $data['deploy_key'] ?? false;
        // A bad own key fails here with its own message. Git would only report that GitHub refused the key.
        $ownKey = isset($data['deploy_private_key']) ? $this->sshKeygen->keyPairOf($data['deploy_private_key'])['private'] : null;

        // A wrong repository or branch fails here, before any record exists. A new deploy key cannot read the
        // repository until the user adds it on GitHub, so that path makes no remote call and takes main as the default branch.
        $branch = ($data['branch'] ?? null) ?: null;

        if ($deployKey) {
            $branch ??= 'main';
        } else {
            $access = $this->repositoryProbe->access(new Application(['repository' => $data['repository'], 'deploy_private_key' => $ownKey]), $branch);

            if (! $access->read->works()) {
                throw ValidationException::withMessages([match ($access->read) {
                    RepositoryRead::BRANCH_MISSING => 'branch',
                    RepositoryRead::KEY_REFUSED => 'deploy_private_key',
                    default => 'repository',
                } => $access->summary()]);
            }

            // An empty repository has no HEAD, so git finds no default branch.
            $branch ??= $access->defaultBranch ?? throw ValidationException::withMessages(['branch' => 'The repository has no branch yet. Push a commit first.']);
        }

        return DB::transaction(function () use ($by, $data, $type, $branch, $deployKey, $ownKey) {
            $application = Application::create([
                'name' => $data['name'],
                'repository' => $data['repository'],
                'cluster_id' => $data['cluster_id'],
                'type' => $type,
                'port' => $type->choosesPort() ? ($data['port'] ?? $type->defaultPort()) : $type->defaultPort(),
            ]);

            $settings = [
                'deploy_commands' => $type->deployCommands(),
                'health_path' => $type->healthPath(),
            ];

            $production = $application->environments()->create([
                'name' => 'production',
                'kind' => EnvironmentKind::PERMANENT,
                'is_protected' => true,
                'branch' => $branch,
                ...$settings,
            ]);

            $template = $application->environments()->create([
                'name' => 'preview-template',
                'kind' => EnvironmentKind::PREVIEW_TEMPLATE,
                'is_protected' => false,
                'auto_deploy' => false,
                'sleep_after_minutes' => Environment::PREVIEW_SLEEP_AFTER_MINUTES,
                ...$settings,
            ]);

            AuditEvent::record($by, 'application.created', $application, ['repository' => $application->repository, 'type' => $type->value]);

            if ($deployKey || $ownKey !== null) {
                $this->addDeployKey->execute($application, $by, $ownKey);
            }

            // A new process takes the size and the CPU target of web, with one instance. A scheduled process drops the instances.
            foreach ($type->processes() as $process) {
                foreach ([$production, $template] as $environment) {
                    $this->addProcess->execute(
                        $environment, $by, $process['name'], $process['command'], $process['kind'], $process['schedule'] ?? null,
                        $environment->size, 1, 1, $environment->cpu_target,
                    );
                }
            }

            foreach ($type->generatedSecrets() as $key => $value) {
                $this->setEnvVar->execute($production, $by, $key, $value, true, EnvVarAvailability::RUNTIME);
            }

            if ($type->database() !== 'none') {
                $this->attachDatastore->execute($production, $by, DatastoreKind::from($type->database()));
            }

            $this->defaultDomain->add($production, $by);

            return $application;
        });
    }
}
