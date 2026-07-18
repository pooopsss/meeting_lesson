<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use DatabaseMigrations;

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'model@example.com',
            'password' => Hash::make('secret123'),
        ], $overrides));
    }

    public function test_phone_and_avatar_path_are_mass_assignable(): void
    {
        $user = $this->makeUser([
            'phone' => '+7 999 123-45-67',
            'avatar_path' => 'avatars/1.jpg',
        ]);

        $this->assertEquals('+7 999 123-45-67', $user->phone);
        $this->assertEquals('avatars/1.jpg', $user->avatar_path);
        $this->assertEquals('+7 999 123-45-67', $user->fresh()->phone);
        $this->assertEquals('avatars/1.jpg', $user->fresh()->avatar_path);
    }

    public function test_avatar_url_returns_path_when_avatar_path_is_set(): void
    {
        $user = $this->makeUser(['avatar_path' => 'avatars/42.jpg']);

        $this->assertEquals('/api/me/avatar', $user->avatar_url);
    }

    public function test_avatar_url_returns_null_when_avatar_path_is_null(): void
    {
        $user = $this->makeUser();

        $this->assertNull($user->avatar_url);
    }

    public function test_initials_returns_uppercased_first_letters_of_first_two_words(): void
    {
        $user = $this->makeUser();

        $user->name = 'Иван Петров';
        $this->assertEquals('ИП', $user->initials);
    }

    public function test_initials_returns_uppercased_first_letter_for_single_word(): void
    {
        $user = $this->makeUser();

        $user->name = 'madonna';
        $this->assertEquals('M', $user->initials);
    }

    public function test_initials_returns_empty_string_when_name_is_null(): void
    {
        $user = $this->makeUser();

        $this->assertEquals('', $user->initials);
    }

    public function test_initials_returns_empty_string_when_name_is_empty(): void
    {
        $user = $this->makeUser();

        $user->name = '';
        $this->assertEquals('', $user->initials);
    }

    public function test_initials_ignores_words_beyond_the_first_two(): void
    {
        $user = $this->makeUser();

        $user->name = 'Анна Мария Сергеевна';
        $this->assertEquals('АМ', $user->initials);
    }

    public function test_color_returns_a_hex_string_starting_with_hash(): void
    {
        $user = $this->makeUser();

        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $user->color);
    }

    public function test_color_is_deterministic_for_the_same_user_id(): void
    {
        $userA = $this->makeUser(['email' => 'a@example.com']);
        $userB = User::find($userA->id);

        $this->assertEquals($userA->color, $userB->color);
    }

    public function test_color_differs_between_different_user_ids(): void
    {
        $a = $this->makeUser(['email' => 'a@example.com']);
        $b = $this->makeUser(['email' => 'b@example.com']);
        $c = $this->makeUser(['email' => 'c@example.com']);
        $d = $this->makeUser(['email' => 'd@example.com']);
        $e = $this->makeUser(['email' => 'e@example.com']);

        $colors = [$a->color, $b->color, $c->color, $d->color, $e->color];
        $this->assertCount(5, array_unique($colors));
    }

    public function test_appends_includes_avatar_url_initials_and_color_in_array_output(): void
    {
        $user = $this->makeUser([
            'phone' => '+7 999 000-00-00',
            'avatar_path' => 'avatars/7.jpg',
        ]);
        $user->name = 'Иван Петров';

        $array = $user->toArray();

        $this->assertArrayHasKey('avatar_url', $array);
        $this->assertArrayHasKey('initials', $array);
        $this->assertArrayHasKey('color', $array);

        $this->assertEquals('/api/me/avatar', $array['avatar_url']);
        $this->assertEquals('ИП', $array['initials']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $array['color']);
    }
}
