<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Architecture\Enums\ObjectType;

final class RegistrationTest extends ArchitectureTestCase
{
    public function test_queues_are_consumed_by_the_dashboard_pod(): void
    {
        preg_match_all('/queue:work\b.*--queue=([\w,-]+)/', (string) file_get_contents($this->absolute('docker/entrypoint.sh')), $matches);
        $queues = array_merge(...array_map(fn (string $list): array => explode(',', $list), $matches[1]));

        self::assertNotSame([], $queues, 'docker/entrypoint.sh starts no queue:work --queue=... worker.');

        $unconsumed = fn (Node $node): bool => ($this->isMethodCall($node, 'onQueue', 'allOnQueue')
            && ($node->getArgs()[0]->value ?? null) instanceof Node\Scalar\String_
            && ! in_array($node->getArgs()[0]->value->value, $queues, true))
            || ($node instanceof Node\PropertyItem
                && $node->name->toString() === 'queue'
                && $node->default instanceof Node\Scalar\String_
                && ! in_array($node->default->value, $queues, true));

        $this->assertMatchesBaseline(
            'queues.consumed-by-the-pod',
            'Use a queue that a queue:work worker in docker/entrypoint.sh consumes ('.implode(', ', $queues).'), or add a worker there and in platform/dashboard.yaml. A job on any other queue never runs. See .claude/rules/registration-checklists.md.',
            [...$this->find($this->layer(), $unconsumed), ...$this->find($this->phpFiles('routes'), $unconsumed)],
        );
    }

    public function test_service_providers_are_registered(): void
    {
        $registered = [
            ...$this->classConstants($this->absolute('bootstrap/providers.php')),
            ...array_merge(...array_map(
                fn (ObjectDescription $provider): array => $this->classConstants(
                    $provider->path,
                    fn (Node $node): bool => $this->isMethodCall($node, 'register'),
                ),
                iterator_to_array($this->subclassesOf(ServiceProvider::class), false),
            )),
        ];

        $this->assertMatchesBaseline(
            'providers.registered',
            'List the service provider in bootstrap/providers.php, or register it from another provider with $this->app->register(). An unlisted provider never boots. See .claude/rules/registration-checklists.md.',
            $this->find(
                $this->subclassesOf(ServiceProvider::class)->leave(fn (ObjectDescription $object): bool => ! $object->reflectionClass->isAbstract()
                    && ! in_array($object->name, $registered, true)),
                fn (Node $node): bool => $node instanceof Node\Stmt\Class_,
            ),
        );
    }

    public function test_middleware_is_registered(): void
    {
        $registered = array_merge(...array_map($this->classConstants(...), [
            $this->absolute('bootstrap/app.php'),
            ...glob($this->absolute('config/*.php')),
            ...glob($this->absolute('routes/*.php')),
        ]));

        $this->assertMatchesBaseline(
            'middleware.registered',
            'Register the middleware in bootstrap/app.php or a routes file. Unregistered middleware never runs. See .claude/rules/registration-checklists.md.',
            $this->find(
                $this->classes('/\\\\Middleware\\\\/')->leave(fn (ObjectDescription $object): bool => $object->type === ObjectType::_CLASS
                    && ! $object->reflectionClass->isAbstract()
                    && ! in_array($object->name, $registered, true)),
                fn (Node $node): bool => $node instanceof Node\Stmt\Class_,
            ),
        );
    }

    public function test_models_have_a_morph_alias(): void
    {
        $aliased = $this->classConstants(
            $this->absolute('app/Providers/AppServiceProvider.php'),
            fn (Node $node): bool => $this->isStaticCall($node, Relation::class, 'enforceMorphMap'),
        );

        $this->assertMatchesBaseline(
            'models.morph-alias',
            'Add the model to Relation::enforceMorphMap() in AppServiceProvider. An audit event can point at any model, so each model needs an alias for the subject_type column. See .claude/rules/registration-checklists.md.',
            $this->find(
                $this->subclassesOf(Model::class)->leave(fn (ObjectDescription $object): bool => ! $object->reflectionClass->isAbstract()
                    && ! in_array($object->name, $aliased, true)),
                fn (Node $node): bool => $node instanceof Node\Stmt\Class_,
            ),
        );
    }

    /** @return list<string> */
    private function classConstants(string $path, ?Closure $within = null): array
    {
        $scope = $within === null ? $this->ast($path) : (new NodeFinder)->find($this->ast($path), $within);

        return array_map(
            fn (Node\Expr\ClassConstFetch $fetch): string => $fetch->class instanceof Node\Name ? $fetch->class->toString() : '',
            (new NodeFinder)->findInstanceOf($scope, Node\Expr\ClassConstFetch::class),
        );
    }
}
