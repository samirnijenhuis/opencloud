<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Builds\Git;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Actions\StartDeployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use App\Domains\Routing\DefaultDomain;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Adds a permanent environment for a branch. It copies the settings, the processes, and the user env values of production
 * or of the preview template, and it gets standard resources, a free domain, and a first deployment of its own.
 */
class CreateEnvironment
{
    private const SETTINGS = ['size', 'min_instances', 'max_instances', 'cpu_target', 'build_command', 'deploy_commands', 'health_path'];

    public function __construct(
        private ConnectionInterface $db,
        private AttachDatastore $attachDatastore,
        private DefaultDomain $defaultDomain,
        private StartDeployment $startDeployment,
    ) {}

    /**
     * @param  Environment  $from  production or the preview template of the application
     *
     * @throws ValidationException when git cannot read the branch
     */
    public function execute(Application $application, User $by, string $name, string $branch, Environment $from): Environment
    {
        // A wrong branch fails here, before any record exists.
        try {
            $commitSha = Git::head($application, $branch);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['branch' => $e->getMessage()]);
        }

        $production = $application->environments()->where('name', 'production')->sole();
        // The default comes first, so it is the default of the new environment too. A database that DigitalOcean destroys has no name.
        $attachments = $production->datastoreAttachments()->with('datastore')->orderByDesc('is_default')->orderBy('id')->get()
            ->reject(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind->family()->isNamed() && $attachment->name === null);

        $environment = $this->db->transaction(function () use ($application, $by, $name, $branch, $from, $attachments) {
            $environment = $application->environments()->create([
                ...$from->only(self::SETTINGS),
                // A copy of the preview template runs web as one instance, like a preview.
                ...($from->kind === EnvironmentKind::PREVIEW_TEMPLATE ? ['min_instances' => 1, 'max_instances' => 1] : []),
                'name' => $name,
                'kind' => EnvironmentKind::PERMANENT,
                'branch' => $branch,
                'is_protected' => false,
            ]);

            foreach ($from->processes as $process) {
                $environment->processes()->create($process->only(['name', 'kind', 'command', 'schedule', 'size', 'min_instances', 'max_instances', 'cpu_target']));
            }

            $copied = $from->envVars()->where('source', EnvVarSource::USER)->orderBy('key')->get()
                ->map(fn (EnvVar $var) => $environment->envVars()->create($var->only(['key', 'value', 'is_secret', 'available_at', 'source']))->key);
            $generated = array_diff_key($application->type->generatedSecrets(), array_flip($copied->all()));

            foreach ($generated as $key => $value) {
                $environment->envVars()->create(['key' => $key, 'value' => $value, 'is_secret' => true, 'available_at' => EnvVarAvailability::RUNTIME]);
            }

            AuditEvent::record($by, 'environment.created', $environment, [
                'branch' => $branch,
                'copied_from' => $from->name,
                'processes' => $from->processes->pluck('name')->all(),
                'env_keys' => $copied->all(),
                'generated' => array_keys($generated),
            ]);

            // Each resource is a new standard one, so the environment never shares the data of production and never creates a paid cloud resource.
            foreach ($attachments as $attachment) {
                $this->attachDatastore->execute($environment, $by, $attachment->datastore->kind, DatastoreSource::STANDARD, $attachment->name);
            }

            $this->defaultDomain->add($environment, $by);

            return $environment;
        });

        $this->startDeployment->execute($environment, $by, $commitSha);

        return $environment;
    }
}
