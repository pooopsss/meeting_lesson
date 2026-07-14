<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use DatabaseMigrations;

    private function seedUser(string $email = 'user@example.com', string $password = 'secret123'): int
    {
        return app('db')->table('users')->insertGetId([
            'email' => $email,
            'password' => Hash::make($password),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_logs_in_with_valid_credentials_and_returns_an_auth_token(): void
    {
        $this->seedUser();

        $this->post('/api/login', [
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $this->seeStatusCode(200);
        $this->seeJsonStructure([
            'token',
            'user' => ['id', 'email'],
        ]);

        $body = json_decode($this->response->getContent(), true);
        $this->assertNotEmpty($body['token']);
        $this->assertIsString($body['token']);
        $this->assertEquals('user@example.com', $body['user']['email']);
        $this->assertArrayNotHasKey('password', $body['user']);
    }

    public function test_login_creates_a_user_session_row_holding_the_token(): void
    {
        $userId = $this->seedUser();

        $this->post('/api/login', [
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $this->seeStatusCode(200);
        $body = json_decode($this->response->getContent(), true);

        $this->seeInDatabase('user_sessions', ['user_id' => $userId]);

        $session = app('db')->table('user_sessions')->where('user_id', $userId)->first();
        $this->assertNotNull($session);
        $this->assertNotEmpty($session->token);
        $this->assertTrue(Hash::check($body['token'], $session->token));
    }

    public function test_login_issues_a_distinct_token_on_each_login(): void
    {
        $userId = $this->seedUser();

        $this->post('/api/login', ['email' => 'user@example.com', 'password' => 'secret123']);
        $firstToken = json_decode($this->response->getContent(), true)['token'];

        $this->post('/api/login', ['email' => 'user@example.com', 'password' => 'secret123']);
        $secondToken = json_decode($this->response->getContent(), true)['token'];

        $this->assertNotEquals($firstToken, $secondToken);
        $this->assertEquals(2, app('db')->table('user_sessions')->where('user_id', $userId)->count());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->seedUser();

        $this->post('/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $this->seeStatusCode(401);
        $this->notSeeInDatabase('user_sessions', []);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $this->post('/api/login', [
            'email' => 'ghost@example.com',
            'password' => 'secret123',
        ]);

        $this->seeStatusCode(401);
    }

    public function test_login_requires_a_valid_email(): void
    {
        $this->post('/api/login', [
            'email' => 'not-an-email',
            'password' => 'secret123',
        ]);

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['email']]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->post('/api/login', []);

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['email', 'password']]);
    }
}
