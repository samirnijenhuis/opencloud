<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'setup-token-from-the-installer-0123456789';

    private const OWNER = [
        'name' => 'Samir',
        'email' => 'samir@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['opencloud.setup_token' => self::TOKEN]);
    }

    public function test_the_setup_page_shows_the_owner_form_for_the_right_token(): void
    {
        $this->get('/setup/'.self::TOKEN)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('setup')->where('token', self::TOKEN));
    }

    public function test_a_wrong_token_gives_404(): void
    {
        $this->get('/setup/wrong-token')->assertNotFound();
        $this->post('/setup/wrong-token', self::OWNER)->assertNotFound();

        $this->assertSame(0, User::count());
    }

    public function test_the_right_token_creates_the_owner_and_logs_them_in(): void
    {
        $this->post('/setup/'.self::TOKEN, self::OWNER)->assertRedirect('/dashboard');

        $owner = User::sole();
        $this->assertSame('samir@example.com', $owner->email);
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertAuthenticatedAs($owner);

        $event = AuditEvent::sole();
        $this->assertSame('owner.created', $event->action);
        $this->assertSame($owner->id, $event->user_id);
    }

    public function test_setup_works_only_once(): void
    {
        $this->post('/setup/'.self::TOKEN, self::OWNER)->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->get('/setup/'.self::TOKEN)->assertNotFound();
        $this->post('/setup/'.self::TOKEN, [...self::OWNER, 'email' => 'mallory@example.com'])->assertNotFound();

        $this->assertSame(['samir@example.com'], User::pluck('email')->all());
    }

    public function test_setup_is_closed_when_the_token_is_empty(): void
    {
        config(['opencloud.setup_token' => '']);
        $this->get('/setup/anything')->assertNotFound();
        $this->post('/setup/anything', self::OWNER)->assertNotFound();

        config(['opencloud.setup_token' => null]);
        $this->get('/setup/anything')->assertNotFound();

        $this->assertSame(0, User::count());
    }

    public function test_setup_validates_the_owner_account(): void
    {
        $this->post('/setup/'.self::TOKEN, [...self::OWNER, 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_open_registration_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', self::OWNER)->assertNotFound();

        $this->assertSame(0, User::count());
    }
}
