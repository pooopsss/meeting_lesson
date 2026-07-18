<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class AvatarTest extends TestCase
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

    private function makeJpeg(int $w = 800, int $h = 600, string $name = 'avatar.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tst_');
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 100, 50));
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    private function makePng(int $w = 200, int $h = 200, string $name = 'avatar.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tst_');
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 200, 0));
        imagepng($img, $tmp);
        imagedestroy($img);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function makeWebp(int $w = 200, int $h = 200, string $name = 'avatar.webp'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tst_');
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 200));
        imagewebp($img, $tmp, 90);
        imagedestroy($img);

        return new UploadedFile($tmp, $name, 'image/webp', null, true);
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

    public function test_upload_avatar_returns_401_when_unauthenticated(): void
    {
        $this->call('POST', '/api/me/avatar', [], [], ['avatar' => $this->makeJpeg()]);

        $this->seeStatusCode(401);
    }

    public function test_upload_avatar_rejects_missing_avatar_field(): void
    {
        $user = $this->makeUser();

        $this->post('/api/me/avatar', [], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['avatar']]);
    }

    public function test_upload_avatar_accepts_jpeg_and_saves_resized_file(): void
    {
        $user = $this->makeUser();

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $this->makeJpeg(800, 600, 'me.jpg'),
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->seeJsonStructure(['id', 'name', 'avatar_url']);
        $this->seeJson(['avatar_url' => '/api/me/avatar']);

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists(self::AVATARS_DIR . '/' . $user->id . '.jpg'));

        $size = getimagesize($disk->path(self::AVATARS_DIR . '/' . $user->id . '.jpg'));
        $this->assertEquals(400, $size[0]);
        $this->assertEquals(400, $size[1]);
    }

    public function test_upload_avatar_accepts_png(): void
    {
        $user = $this->makeUser();

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $this->makePng(),
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->assertTrue(Storage::disk('local')->exists(self::AVATARS_DIR . '/' . $user->id . '.png'));
    }

    public function test_upload_avatar_accepts_webp(): void
    {
        $user = $this->makeUser();

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $this->makeWebp(),
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $this->assertTrue(Storage::disk('local')->exists(self::AVATARS_DIR . '/' . $user->id . '.webp'));
    }

    public function test_upload_avatar_rejects_non_image_mime(): void
    {
        $user = $this->makeUser();

        $tmp = tempnam(sys_get_temp_dir(), 'tst_');
        file_put_contents($tmp, 'not really an image');

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => new UploadedFile($tmp, 'fake.jpg', 'image/jpeg', null, true),
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['avatar']]);
    }

    public function test_upload_avatar_rejects_oversized_file(): void
    {
        $user = $this->makeUser();

        $tmp = tempnam(sys_get_temp_dir(), 'big_');
        $img = imagecreatetruecolor(4000, 4000);
        for ($x = 0; $x < 4000; $x += 50) {
            for ($y = 0; $y < 4000; $y += 50) {
                imagefilledrectangle($img, $x, $y, $x + 40, $y + 40, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        imagejpeg($img, $tmp, 100);
        imagedestroy($img);

        $file = new UploadedFile($tmp, 'big.jpg', 'image/jpeg', null, true);
        $size = filesize($tmp);

        if ($size <= 2 * 1024 * 1024) {
            $this->markTestSkipped('Test image was smaller than 2 MB; cannot exercise size limit.');
        }

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $file,
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['avatar']]);
    }

    public function test_upload_avatar_replaces_existing_avatar(): void
    {
        $user = $this->makeUser();

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $this->makeJpeg(800, 600, 'old.jpg'),
        ], $this->authHeadersFor($user));
        $this->seeStatusCode(200);
        $this->assertTrue(Storage::disk('local')->exists(self::AVATARS_DIR . '/' . $user->id . '.jpg'));

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $this->makePng(500, 500, 'new.png'),
        ], $this->authHeadersFor($user));
        $this->seeStatusCode(200);
        $this->assertTrue(Storage::disk('local')->exists(self::AVATARS_DIR . '/' . $user->id . '.png'));
        $this->assertFalse(Storage::disk('local')->exists(self::AVATARS_DIR . '/' . $user->id . '.jpg'));

        $this->seeJson(['avatar_url' => '/api/me/avatar']);
    }

    public function test_upload_avatar_persists_avatar_path_in_database(): void
    {
        $user = $this->makeUser();

        $this->call('POST', '/api/me/avatar', [], [], [
            'avatar' => $this->makeJpeg(),
        ], $this->authHeadersFor($user));

        $this->seeStatusCode(200);
        $fresh = $user->fresh();
        $this->assertEquals('avatars/' . $user->id . '.jpg', $fresh->avatar_path);
    }
}
