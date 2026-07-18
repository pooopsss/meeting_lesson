<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use App\Services\AvatarService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class AvatarDeleteTest extends TestCase
{
    use DatabaseMigrations;

    private const AVATARS_DIR = 'avatars';

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'avatar@example.com',
            'password' => Hash::make('secret123'),
        ], $overrides));
    }

    private function authHeadersFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . UserSession::issueToken($user)];
    }

    private function uploadJpeg(User $user): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tst_');
        $img = imagecreatetruecolor(400, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 200, 30));
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => new UploadedFile($tmp, 'me.jpg', 'image/jpeg', null, true),
        ], $this->authHeadersFor($user));
    }

    protected function tearDown(): void
    {
        $disk = Storage::disk('local');
        if (is_dir($disk->path(self::AVATARS_DIR))) {
            foreach (glob($disk->path(self::AVATARS_DIR) . '/*') ?: [] as $f) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    public function test_delete_avatar_returns_401_when_unauthenticated(): void
    {
        $this->delete('/api/me/avatar');
        $this->seeStatusCode(401);
    }

    public function test_delete_avatar_is_idempotent_when_no_avatar(): void
    {
        $user = $this->makeUser();

        $this->delete('/api/me/avatar', [], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJson(['avatar_url' => null]);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_delete_avatar_removes_file_and_clears_path(): void
    {
        $user = $this->makeUser();
        $this->uploadJpeg($user);
        $this->assertEquals('avatars/' . $user->id . '.jpg', $user->fresh()->avatar_path);
        $this->assertTrue(Storage::disk('local')->exists('avatars/' . $user->id . '.jpg'));

        $this->delete('/api/me/avatar', [], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJson(['avatar_url' => null]);
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertFalse(Storage::disk('local')->exists('avatars/' . $user->id . '.jpg'));
    }

    public function test_delete_avatar_only_affects_current_user(): void
    {
        $alice = $this->makeUser(['email' => 'alice@example.com']);
        $bob = $this->makeUser(['email' => 'bob@example.com']);
        $this->uploadJpeg($alice);
        $this->uploadJpeg($bob);

        $this->delete('/api/me/avatar', [], $this->authHeadersFor($alice));

        $this->seeStatusCode(200);
        $this->assertNull($alice->fresh()->avatar_path);
        $this->assertEquals('avatars/' . $bob->id . '.jpg', $bob->fresh()->avatar_path);
        $this->assertFalse(Storage::disk('local')->exists('avatars/' . $alice->id . '.jpg'));
        $this->assertTrue(Storage::disk('local')->exists('avatars/' . $bob->id . '.jpg'));
    }

    public function test_delete_avatar_can_be_repeated_idempotently(): void
    {
        $user = $this->makeUser();
        $this->uploadJpeg($user);

        $this->delete('/api/me/avatar', [], $this->authHeadersFor($user));
        $this->seeStatusCode(200);
        $this->delete('/api/me/avatar', [], $this->authHeadersFor($user));
        $this->seeStatusCode(200);
        $this->seeJson(['avatar_url' => null]);
    }
}
