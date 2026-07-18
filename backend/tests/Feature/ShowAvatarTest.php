<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class ShowAvatarTest extends TestCase
{
    use DatabaseMigrations;

    private const AVATARS_DIR = 'avatars';

    private function makeUser(): User
    {
        $user = User::create([
            'email' => 'show@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $user->name = 'X';
        $user->save();

        return $user;
    }

    private function authHeadersFor(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . UserSession::issueToken($user)];
    }

    private function uploadJpeg(User $user, int $w = 300, int $h = 300): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tst_');
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 50, 200, 100));
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

    public function test_show_avatar_returns_401_when_unauthenticated(): void
    {
        $this->get('/api/me/avatar');
        $this->seeStatusCode(401);
    }

    public function test_show_avatar_returns_404_when_user_has_no_avatar(): void
    {
        $user = $this->makeUser();

        $this->get('/api/me/avatar', $this->authHeadersFor($user));

        $this->seeStatusCode(404);
    }

    public function test_show_avatar_returns_image_with_correct_content_type(): void
    {
        $user = $this->makeUser();
        $this->uploadJpeg($user);

        $this->get('/api/me/avatar', $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->assertEquals('image/jpeg', $this->response->headers->get('Content-Type'));
    }

    public function test_show_avatar_returns_inline_content_disposition_with_filename(): void
    {
        $user = $this->makeUser();
        $this->uploadJpeg($user);

        $this->get('/api/me/avatar', $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $cd = $this->response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $cd);
        $this->assertStringContainsString('avatar.jpg', $cd);
    }

    public function test_show_avatar_sets_private_cache_control(): void
    {
        $user = $this->makeUser();
        $this->uploadJpeg($user);

        $this->get('/api/me/avatar', $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $cache = $this->response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age', $cache);
    }

    public function test_show_avatar_returns_404_when_file_missing_on_disk(): void
    {
        $user = $this->makeUser();
        $user->avatar_path = 'avatars/' . $user->id . '.jpg';
        $user->save();

        $this->get('/api/me/avatar', $this->authHeadersFor($user));

        $this->seeStatusCode(404);
    }

    public function test_show_avatar_only_for_owner(): void
    {
        $alice = $this->makeUser();
        $alice->email = 'alice@example.com';
        $alice->name = 'A';
        $alice->save();
        $bob = User::create([
            'email' => 'bob@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $bob->name = 'B';
        $bob->save();
        $this->uploadJpeg($alice);

        $this->get('/api/me/avatar', $this->authHeadersFor($bob));
        $this->seeStatusCode(404);

        $this->get('/api/me/avatar', $this->authHeadersFor($alice));
        $this->seeStatusCode(200);
    }
}
