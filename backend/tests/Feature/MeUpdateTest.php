<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class MeUpdateTest extends TestCase
{
    use DatabaseMigrations;

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'me@example.com',
            'password' => Hash::make('secret123'),
        ], $overrides));
    }

    private function authHeadersFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . UserSession::issueToken($user)];
    }

    public function test_patch_me_returns_401_when_unauthenticated(): void
    {
        $this->patch('/api/me', ['name' => 'X']);

        $this->seeStatusCode(401);
    }

    public function test_patch_me_updates_name_and_returns_full_profile(): void
    {
        $user = $this->makeUser();
        $user->name = 'Иван';
        $user->save();

        $this->patch('/api/me', ['name' => 'Пётр Сидоров'], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJsonStructure(['id', 'name', 'email', 'phone', 'avatar_url', 'initials', 'color']);
        $this->seeJson(['name' => 'Пётр Сидоров']);
        $this->assertEquals('Пётр Сидоров', $user->fresh()->name);
    }

    public function test_patch_me_updates_phone(): void
    {
        $user = $this->makeUser();
        $user->name = 'X';
        $user->save();

        $this->patch('/api/me', [
            'name' => 'X',
            'phone' => '+7 (999) 123-45-67',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJson(['phone' => '+7 (999) 123-45-67']);
    }

    public function test_patch_me_can_clear_phone_by_sending_empty_string(): void
    {
        $user = $this->makeUser(['phone' => '+7 999']);
        $user->name = 'X';
        $user->save();

        $this->patch('/api/me', [
            'name' => 'X',
            'phone' => '',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJson(['phone' => null]);
    }

    public function test_patch_me_ignores_email_in_request_body(): void
    {
        $user = $this->makeUser(['email' => 'original@example.com']);
        $user->name = 'X';
        $user->save();

        $this->patch('/api/me', [
            'name' => 'X',
            'email' => 'attacker@example.com',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->assertEquals('original@example.com', $user->fresh()->email);
    }

    public function test_patch_me_returns_422_when_name_is_missing(): void
    {
        $user = $this->makeUser();

        $this->patch('/api/me', [], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['name']]);
    }

    public function test_patch_me_returns_422_when_name_too_long(): void
    {
        $user = $this->makeUser();

        $this->patch('/api/me', ['name' => str_repeat('a', 256)], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['name']]);
    }

    public function test_patch_me_returns_422_when_phone_has_invalid_chars(): void
    {
        $user = $this->makeUser();

        $this->patch('/api/me', [
            'name' => 'X',
            'phone' => 'phone: 123; DROP TABLE',
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['phone']]);
    }

    public function test_patch_me_returns_422_when_phone_too_long(): void
    {
        $user = $this->makeUser();

        $this->patch('/api/me', [
            'name' => 'X',
            'phone' => str_repeat('1', 21),
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['phone']]);
    }

    public function test_patch_me_validation_messages_are_in_russian(): void
    {
        $user = $this->makeUser();

        $this->patch('/api/me', [], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $body = json_decode($this->response->getContent(), true);
        $this->assertStringContainsString('имя', $body['errors']['name'][0]);
    }
}
