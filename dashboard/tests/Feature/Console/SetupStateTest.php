<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_is_open_until_the_owner_exists(): void
    {
        $this->artisan('opencloud:setup-state')->expectsOutput('open')->assertSuccessful();

        User::factory()->create();

        $this->artisan('opencloud:setup-state')->expectsOutput('done')->assertSuccessful();
    }
}
