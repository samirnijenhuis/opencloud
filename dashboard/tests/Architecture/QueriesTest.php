<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Audit\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PHPUnit\Architecture\Elements\ObjectDescription;

final class QueriesTest extends ArchitectureTestCase
{
    public function test_queries_go_through_eloquent_models(): void
    {
        $this->assertMatchesBaseline(
            'queries.no-db-table',
            'Query through the Eloquent model, not DB::table(). See .claude/rules/architecture.md.',
            $this->find($this->layer(), fn (Node $node): bool => $this->isStaticCall($node, DB::class, 'table')),
        );
    }

    public function test_sql_does_not_wrap_columns_in_lower(): void
    {
        $this->assertMatchesBaseline(
            'queries.no-lower',
            'LOWER(column) stops the index. Store the value in one case and compare it with where(). See .claude/rules/architecture.md.',
            $this->find($this->layer(), fn (Node $node): bool => $node instanceof Node\Scalar\String_
                && preg_match('/\blower\s*\(/i', $node->value) === 1),
        );
    }

    public function test_only_actions_and_jobs_mutate(): void
    {
        $others = $this->layer()->exclude(fn (ObjectDescription $object): bool => str_contains($object->name, '\\Actions\\')
            || str_contains($object->name, '\\Jobs\\')
            || str_starts_with($object->name, 'App\\Http\\Controllers\\')
            || $object->name === AuditEvent::class);

        $this->assertMatchesBaseline(
            'mutations.only-in-actions-and-jobs',
            'Mutate in an Action, or in a queued Job; services, provisioners, observers, commands and models do not write. See .claude/rules/actions-jobs.md.',
            $this->find($others, $this->isMutation(...)),
        );
    }
}
