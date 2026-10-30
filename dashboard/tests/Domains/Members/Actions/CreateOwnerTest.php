<?php

namespace Tests\Domains\Members\Actions;

use App\Domains\Members\Actions\CreateOwner;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_setup_request_creates_no_second_owner(): void
    {
        User::factory()->owner()->create(['email' => 'first@example.com']);

        try {
            app(CreateOwner::class)->execute(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'a-long-password']);
            $this->fail('A second owner was created.');
        } catch (ValidationException $e) {
            $this->assertSame(['email' => ['Opencloud has an owner already. Sign in instead.']], $e->errors());
        }

        $this->assertSame(['first@example.com'], User::query()->pluck('email')->all());
    }
}
