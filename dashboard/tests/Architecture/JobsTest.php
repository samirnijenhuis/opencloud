<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use PhpParser\Node;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Architecture\Enums\ObjectType;

final class JobsTest extends ArchitectureTestCase
{
    public function test_jobs_are_queued(): void
    {
        $this->assertMatchesBaseline(
            'jobs.queued',
            'A job implements ShouldQueue; run synchronous work in the Action itself. See .claude/rules/actions-jobs.md.',
            $this->find(
                $this->classes('/\\\\Jobs\\\\/')->leave(fn (ObjectDescription $object): bool => $object->type === ObjectType::_CLASS
                    && ! $object->reflectionClass->isAbstract()
                    && ! $object->reflectionClass->implementsInterface(ShouldQueue::class)),
                fn (Node $node): bool => $node instanceof Node\Stmt\Class_,
            ),
        );
    }

    public function test_jobs_have_no_job_suffix(): void
    {
        $this->assertMatchesBaseline(
            'jobs.no-job-suffix',
            'Name a job by its verb phrase, without a Job suffix: ProvisionCluster. See .claude/rules/naming.md.',
            $this->namedWith($this->layer(), 'Job'),
        );
    }

    public function test_jobs_live_in_a_domain_jobs_directory(): void
    {
        $this->assertMatchesBaseline(
            'jobs.placement',
            'Put a job in app/Domains/{Domain}/Jobs. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->layer()->leave(fn (ObjectDescription $object): bool => in_array(Dispatchable::class, class_uses_recursive($object->name), true)),
                '/^App\\\\Domains\\\\(\w+\\\\)+Jobs\\\\\w+$/',
            ),
        );
    }

    public function test_queued_classes_are_jobs(): void
    {
        $this->assertMatchesBaseline(
            'queues.queued-class-placement',
            'Only jobs implement ShouldQueue. Move queued work into a job in a Jobs directory. See .claude/rules/actions-jobs.md.',
            $this->placedOutside(
                $this->layer()->leave(fn (ObjectDescription $object): bool => $object->type === ObjectType::_CLASS
                    && ! $object->reflectionClass->isAbstract()
                    && $object->reflectionClass->implementsInterface(ShouldQueue::class)),
                '/\\\\Jobs\\\\/',
            ),
        );
    }
}
