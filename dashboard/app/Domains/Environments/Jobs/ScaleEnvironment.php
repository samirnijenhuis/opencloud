<?php

namespace App\Domains\Environments\Jobs;

use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Deployments\EnvironmentManifests;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;
use App\Support\Kube\Labels;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makes the pods of one environment match its sleep state. Asleep: no autoscaler, zero pods for web and each process, the
 * CronJobs suspended, and its own Valkey stopped. Awake: each Deployment at its minimum, the autoscalers back, the CronJobs
 * running, and Valkey started. The job changes counts only, never a pod template, so it cannot undo a deploy. It reads the
 * state when it runs, so a sleep and a quick wake end awake, and one waiting job covers each later dispatch.
 */
class ScaleEnvironment implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Each run sends the whole state again, so a run after a failed kubectl call is safe. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** A preview can close before the job runs. */
    public bool $deleteWhenMissingModels = true;

    /** The lock must expire on its own, because a job that fails before it runs never releases it. */
    public int $uniqueFor = 60;

    public function __construct(public Environment $environment) {}

    public function uniqueId(): string
    {
        return (string) $this->environment->id;
    }

    public function handle(): void
    {
        $environment = $this->environment;
        $asleep = $environment->slept_at !== null;
        $kubectl = Kubectl::for($environment->application->cluster);
        $namespace = $environment->kubeNamespace();
        $selector = [Labels::ENVIRONMENT => (string) $environment->id];
        $manifests = new EnvironmentManifests($environment);

        // An autoscaler keeps its minimum of pods, so it goes first.
        if ($asleep) {
            $kubectl->run([
                'delete', 'horizontalpodautoscaler', '--selector='.Labels::ENVIRONMENT."={$environment->id},".Labels::PROCESS, "--namespace={$namespace}", '--ignore-not-found', '--wait=false',
            ])->throw();
        }

        $minimum = $manifests->minimumReplicas();

        foreach ($kubectl->get('deployments', namespace: $namespace, selector: $selector)['items'] ?? [] as $deployment) {
            // The Deployment of a removed process stays at zero until the next deploy deletes it.
            $replicas = $asleep ? 0 : ($minimum[$deployment['metadata']['name']] ?? 0);
            $kubectl->run(['scale', "deployment/{$deployment['metadata']['name']}", "--replicas={$replicas}", "--namespace={$namespace}"])->throw();
        }

        if (! $asleep && ($autoscalers = $manifests->autoscalers()) !== []) {
            $kubectl->apply($autoscalers);
        }

        foreach ($kubectl->get('cronjobs', namespace: $namespace, selector: $selector)['items'] ?? [] as $cronJob) {
            $kubectl->run([
                'patch', "cronjob/{$cronJob['metadata']['name']}", "--namespace={$namespace}", '--type=merge', '--patch='.json_encode(['spec' => ['suspend' => $asleep]]),
            ])->throw();
        }

        $environment->datastoreAttachments()->where('status', DatastoreStatus::READY)->with('datastore.cluster')->get()
            ->each(fn (DatastoreAttachment $attachment) => Provisioners::of($attachment->datastore)->scale($attachment, awake: ! $asleep));
    }
}
