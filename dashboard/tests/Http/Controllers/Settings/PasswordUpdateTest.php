<?php

namespace Tests\Http\Controllers\Settings;

use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_a_password_change_logs_out_the_other_sessions_and_keeps_the_current_one()
    {
        $user = User::factory()->create();
        $hashOfOldPassword = $this->app['auth']->guard()->hashPasswordForCookie($user->password);
        $this->actingAs($user)->withSession(['password_hash_web' => $hashOfOldPassword]);

        $this->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->get('/settings/password')->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->flushSession()->withSession(['password_hash_web' => $hashOfOldPassword]);

        $this->get('/settings/password')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/settings/password');
    }
}
