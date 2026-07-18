<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use DatabaseMigrations;

    private function makeUser(string $email = 'pwd@example.com', string $password = 'oldpass1234'): User
    {
        $user = User::create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->name = 'X';
        $user->save();

        return $user;
    }

    private function authHeadersFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . UserSession::issueToken($user)];
    }

    public function test_change_password_returns_401_when_unauthenticated(): void
    {
        $this->post('/api/me/password', [
            'current_password' => 'oldpass1234',
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'newpass1234',
        ]);

        $this->seeStatusCode(401);
    }

    public function test_change_password_succeeds_and_hashes_new_password(): void
    {
        $user = $this->makeUser();

        $this->post('/api/me/password', [
            'current_password' => 'oldpass1234',
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'newpass1234',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpass1234', $fresh->password));
        $this->assertFalse(Hash::check('oldpass1234', $fresh->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();
        $oldHash = $user->password;

        $this->post('/api/me/password', [
            'current_password' => 'WRONG-current',
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'newpass1234',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['current_password']]);
        $this->assertEquals($oldHash, $user->fresh()->password);
    }

    public function test_change_password_returns_422_when_current_password_missing(): void
    {
        $user = $this->makeUser();

        $this->post('/api/me/password', [
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'newpass1234',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['current_password']]);
    }

    public function test_change_password_returns_422_when_new_password_too_short(): void
    {
        $user = $this->makeUser();

        $this->post('/api/me/password', [
            'current_password' => 'oldpass1234',
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['new_password']]);
    }

    public function test_change_password_returns_422_when_confirmation_does_not_match(): void
    {
        $user = $this->makeUser();

        $this->post('/api/me/password', [
            'current_password' => 'oldpass1234',
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'different1234',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['new_password']]);
    }

    public function test_change_password_wrong_current_message_is_in_russian(): void
    {
        $user = $this->makeUser();

        $this->post('/api/me/password', [
            'current_password' => 'WRONG',
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'newpass1234',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $body = json_decode($this->response->getContent(), true);
        $this->assertStringContainsString('Неверный', $body['errors']['current_password'][0]);
    }

    public function test_change_password_does_not_invalidate_existing_sessions(): void
    {
        $user = $this->makeUser();
        $token = UserSession::issueToken($user);

        $this->post('/api/me/password', [
            'current_password' => 'oldpass1234',
            'new_password' => 'newpass1234',
            'new_password_confirmation' => 'newpass1234',
        ], $this->authHeadersFor($user));
        $this->seeStatusCode(200);

        $this->get('/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->seeStatusCode(200);
    }
}
