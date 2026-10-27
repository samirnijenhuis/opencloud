<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PHPUnit\Architecture\Elements\Layer\Layer;

final class ModelsTest extends ArchitectureTestCase
{
    private const array MODEL_EVENTS = [
        'retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted',
        'trashed', 'forceDeleting', 'forceDeleted', 'restoring', 'restored', 'replicating',
    ];

    public function test_models_do_not_eager_load_by_default(): void
    {
        $this->assertMatchesBaseline(
            'models.no-default-eager-loads',
            'Do not set $with; eager load where the data is rendered. See .claude/rules/architecture.md.',
            $this->find($this->models(), fn (Node $node): bool => $node instanceof Node\PropertyItem
                && $node->name->toString() === 'with'),
        );
    }

    public function test_models_use_casts_instead_of_accessors(): void
    {
        $this->assertMatchesBaseline(
            'models.no-accessors',
            'Use a cast, or compute the value where it is rendered, instead of an accessor or mutator. See .claude/rules/architecture.md.',
            $this->find($this->models(), fn (Node $node): bool => $this->isStaticCall($node, Attribute::class, 'make')
                || ($node instanceof Node\Stmt\ClassMethod && preg_match('/^(get|set)\w+Attribute$/', $node->name->toString()) === 1)),
        );
    }

    public function test_models_do_not_fire_model_events(): void
    {
        $this->assertMatchesBaseline(
            'models.no-model-events',
            'Do the follow-up work in the Action or Job that makes the change; no model events or observers. See .claude/rules/actions-jobs.md.',
            $this->find($this->layer(), fn (Node $node): bool => ($node instanceof Node\Expr\StaticCall
                    && $node->class instanceof Node\Name
                    && in_array($node->class->toString(), ['static', 'self'], true)
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), self::MODEL_EVENTS, true))
                || ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'observe')
                || ($node instanceof Node\PropertyItem && $node->name->toString() === 'dispatchesEvents')
                || ($node instanceof Node\Attribute && $node->name->toString() === ObservedBy::class)),
        );
    }

    public function test_models_live_in_a_domain_models_directory(): void
    {
        $this->assertMatchesBaseline(
            'models.placement',
            'Put a model in app/Domains/{Domain}/Models. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->models(),
                '/^App\\\\Domains\\\\(\w+\\\\)+Models\\\\\w+$/',
            ),
        );
    }

    private function models(): Layer
    {
        return $this->subclassesOf(Model::class);
    }
}
