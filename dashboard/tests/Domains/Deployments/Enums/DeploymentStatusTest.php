<?php

namespace Tests\Domains\Deployments\Enums;

use App\Domains\Deployments\Enums\DeploymentStatus as Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeploymentStatusTest extends TestCase
{
    private const ALLOWED = [
        'queued>building',
        'queued>failed',
        'building>deploying',
        'building>failed',
        'deploying>live',
        'deploying>failed',
        'live>superseded',
    ];

    /** @return array<string, array{Status, Status, bool}> */
    public static function moves(): array
    {
        $moves = [];
        foreach (Status::cases() as $from) {
            foreach (Status::cases() as $to) {
                $key = $from->value.'>'.$to->value;
                $moves[$key] = [$from, $to, in_array($key, self::ALLOWED, true)];
            }
        }

        return $moves;
    }

    #[DataProvider('moves')]
    public function test_status_allows_only_the_moves_in_the_architecture(Status $from, Status $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canMoveTo($to));
    }

    public function test_finished_deployments_never_move_again(): void
    {
        $this->assertFalse(Status::FAILED->canMoveTo(Status::QUEUED));
        $this->assertFalse(Status::FAILED->canMoveTo(Status::LIVE));
        $this->assertFalse(Status::SUPERSEDED->canMoveTo(Status::LIVE));
    }

    public function test_a_deployment_cannot_skip_a_step(): void
    {
        $this->assertFalse(Status::QUEUED->canMoveTo(Status::LIVE));
        $this->assertFalse(Status::QUEUED->canMoveTo(Status::DEPLOYING));
        $this->assertFalse(Status::BUILDING->canMoveTo(Status::LIVE));
        $this->assertFalse(Status::LIVE->canMoveTo(Status::FAILED));
    }
}
