<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use DatabaseMigrations;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'newuser@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    public function test_registers_a_new_user_and_returns_an_auth_token(): void
    {
        $this->post('/api/register', $this->validPayload());

        $this->seeStatusCode(201);
        $this->seeJsonStructure([
            'token',
            'user' => ['id', 'email'],
        ]);

        $body = json_decode($this->response->getContent(), true);
        $this->assertNotEmpty($body['token']);
        $this->assertIsString($body['token']);
        $this->assertEquals('newuser@example.com', $body['user']['email']);
        $this->assertArrayNotHasKey('password', $body['user']);
    }

    public function test_registration_persists_the_user_with_a_hashed_password(): void
    {
        $this->post('/api/register', $this->validPayload());

        $this->seeStatusCode(201);
        $this->seeInDatabase('users', ['email' => 'newuser@example.com']);

        $user = app('db')->table('users')->where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_registration_creates_a_user_session_row_holding_the_token(): void
    {
        $this->post('/api/register', $this->validPayload());

        $this->seeStatusCode(201);
        $body = json_decode($this->response->getContent(), true);

        $user = app('db')->table('users')->where('email', 'newuser@example.com')->first();
        $this->seeInDatabase('user_sessions', ['user_id' => $user->id]);

        $session = app('db')->table('user_sessions')->where('user_id', $user->id)->first();
        $this->assertNotNull($session);
        $this->assertNotEmpty($session->token);
        $this->assertTrue(Hash::check($body['token'], $session->token));
    }

    public function test_registration_fails_when_email_already_exists(): void
    {
        $this->post('/api/register', $this->validPayload());
        $this->seeStatusCode(201);

        $this->post('/api/register', $this->validPayload(['password_confirmation' => 'secret123']));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['email']]);
        $this->assertEquals(1, app('db')->table('users')->where('email', 'newuser@example.com')->count());
    }

    public function test_registration_requires_a_valid_email(): void
    {
        $this->post('/api/register', $this->validPayload(['email' => 'not-an-email']));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['email']]);
    }

    public function test_registration_requires_an_email(): void
    {
        $this->post('/api/register', $this->validPayload(['email' => '']));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['email']]);
    }

    public function test_registration_requires_a_password(): void
    {
        $this->post('/api/register', $this->validPayload(['password' => '', 'password_confirmation' => '']));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['password']]);
    }

    public function test_registration_requires_a_minimum_password_length(): void
    {
        $this->post('/api/register', $this->validPayload(['password' => '123', 'password_confirmation' => '123']));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['password']]);
    }

    public function test_registration_requires_password_confirmation_to_match(): void
    {
        $this->post('/api/register', $this->validPayload(['password_confirmation' => 'different']));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['password']]);
    }
}
