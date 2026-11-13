<?php

namespace Tests\Domains\Environments;

use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\ToolLogin;
use Tests\TestCase;

class ToolLoginTest extends TestCase
{
    public function test_the_login_of_an_environment_is_the_same_on_each_call_and_its_hash_verifies_the_password(): void
    {
        $login = ToolLogin::for($this->environment(12));
        $again = ToolLogin::for($this->environment(12));

        $this->assertSame('opencloud', $login->username);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $login->password);
        $this->assertSame([$login->password, $login->hash()], [$again->password, $again->hash()]);
        $this->assertStringStartsWith('$2y$10$', $login->hash());
        $this->assertTrue(password_verify($login->password, $login->hash()));
    }

    public function test_each_environment_and_each_app_key_give_another_password(): void
    {
        $password = ToolLogin::for($this->environment(12))->password;

        $this->assertNotSame($password, ToolLogin::for($this->environment(13))->password);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->assertNotSame($password, ToolLogin::for($this->environment(12))->password);
    }

    private function environment(int $id): Environment
    {
        $environment = new Environment;
        $environment->id = $id;

        return $environment;
    }
}
