<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use DatabaseMigrations;

    private function makeUser(string $email = 'logout@example.com'): User
    {
        return User::create([
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function authHeadersFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . UserSession::issueToken($user)];
    }

    public function test_logout_returns_401_when_unauthenticated(): void
    {
        $this->post('/api/logout');
        $this->seeStatusCode(401);
    }

    public function test_logout_returns_204_with_no_body(): void
    {
        $user = $this->makeUser();

        $this->post('/api/logout', [], $this->authHeadersFor($user));

        $this->seeStatusCode(204);
        $this->assertEmpty($this->response->getContent());
    }

    public function test_logout_invalidates_the_current_token(): void
    {
        $user = $this->makeUser();
        $headers = $this->authHeadersFor($user);

        $this->get('/api/me', $headers);
        $this->seeStatusCode(200);

        $this->post('/api/logout', [], $headers);
        $this->seeStatusCode(204);

        $this->get('/api/me', $headers);
        $this->seeStatusCode(401);
    }

    public function test_logout_removes_only_the_current_session_not_others(): void
    {
        $user = $this->makeUser();
        $tokenA = UserSession::issueToken($user);
        $tokenB = UserSession::issueToken($user);

        $this->assertEquals(2, app('db')->table('user_sessions')->where('user_id', $user->id)->count());

        $this->post('/api/logout', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        $this->seeStatusCode(204);

        $this->assertEquals(1, app('db')->table('user_sessions')->where('user_id', $user->id)->count());

        $this->get('/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        $this->seeStatusCode(401);

        $this->get('/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        $this->seeStatusCode(200);
    }
}
