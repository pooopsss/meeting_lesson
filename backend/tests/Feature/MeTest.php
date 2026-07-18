<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class MeTest extends TestCase
{
    use DatabaseMigrations;

    private function makeUser(array $overrides = []): User
    {
        $user = User::create(array_merge([
            'email' => 'me@example.com',
            'password' => Hash::make('secret123'),
        ], $overrides));
        $user->name = 'Иван Петров';
        $user->save();

        return $user;
    }

    private function authHeadersFor(User $user): array
    {
        $token = \App\Models\UserSession::issueToken($user);

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_me_returns_401_when_no_token_provided(): void
    {
        $this->get('/api/me');

        $this->seeStatusCode(401);
        $this->seeJson(['message' => 'Требуется авторизация']);
    }

    public function test_me_returns_401_when_token_is_invalid(): void
    {
        $this->get('/api/me', ['Authorization' => 'Bearer not-a-real-token']);

        $this->seeStatusCode(401);
    }

    public function test_me_returns_200_and_full_profile_with_token(): void
    {
        $user = $this->makeUser([
            'phone' => '+7 999 123-45-67',
            'avatar_path' => 'avatars/1.jpg',
        ]);

        $this->get('/api/me', $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJsonStructure([
            'id',
            'name',
            'email',
            'phone',
            'avatar_url',
            'initials',
            'color',
        ]);
        $this->seeJson([
            'id' => $user->id,
            'name' => 'Иван Петров',
            'email' => 'me@example.com',
            'phone' => '+7 999 123-45-67',
            'avatar_url' => '/api/me/avatar',
            'initials' => 'ИП',
        ]);
    }

    public function test_me_returns_null_phone_and_avatar_url_when_not_set(): void
    {
        $user = $this->makeUser();

        $this->get('/api/me', $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJson([
            'phone' => null,
            'avatar_url' => null,
        ]);
    }

    public function test_me_does_not_expose_password_in_response(): void
    {
        $user = $this->makeUser();

        $this->get('/api/me', $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $body = json_decode($this->response->getContent(), true);
        $this->assertArrayNotHasKey('password', $body);
    }
}
