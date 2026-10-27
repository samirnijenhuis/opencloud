<?php

namespace Tests\Feature\Models;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_stores_who_did_what_to_which_subject(): void
    {
        $user = User::factory()->create();
        $environment = Environment::factory()->create();

        AuditEvent::record($user, 'env.updated', $environment, ['key' => 'DB_PASSWORD']);

        $event = AuditEvent::sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('env.updated', $event->action);
        $this->assertSame(Environment::class, $event->subject_type);
        $this->assertSame($environment->id, $event->subject_id);
        $this->assertSame(['key' => 'DB_PASSWORD'], $event->context);
        $this->assertTrue($event->subject->is($environment));
    }

    public function test_record_accepts_no_user_and_no_subject(): void
    {
        AuditEvent::record(null, 'system.started', null);

        $event = AuditEvent::sole();
        $this->assertNull($event->user_id);
        $this->assertNull($event->subject_type);
        $this->assertSame([], $event->context);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function secretContexts(): array
    {
        return [
            'value' => [['key' => 'APP_KEY', 'value' => 'base64:abc']],
            'secret' => [['secret' => 'whsec_abc']],
            'password' => [['password' => 'hunter2']],
            'upper case' => [['Password' => 'hunter2']],
            'nested' => [['changes' => ['value' => 'base64:abc']]],
        ];
    }

    /** @param array<string, mixed> $context */
    #[DataProvider('secretContexts')]
    public function test_record_refuses_a_key_that_can_hold_a_secret(array $context): void
    {
        try {
            AuditEvent::record(User::factory()->create(), 'env.updated', null, $context);
            $this->fail('The audit event stored a secret key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('An audit event cannot store the context key', $e->getMessage());
        }

        $this->assertSame(0, AuditEvent::count());
    }
}
