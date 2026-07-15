<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class FileValidationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function makeUser(string $email = 'owner@example.com'): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
        ]);
    }

    protected function makeMeeting(User $owner): Meeting
    {
        return Meeting::create([
            'user_id' => $owner->id,
            'title' => 'Standup',
            'description' => null,
            'scheduled_at' => '2026-07-15 09:00:00',
        ]);
    }

    protected ?string $authToken = null;

    protected function loginAs(User $user): self
    {
        $this->authToken = UserSession::issueToken($user);

        return $this;
    }

    protected function authHeaders(): array
    {
        return $this->authToken ? ['Authorization' => 'Bearer ' . $this->authToken] : [];
    }

    protected function makeFileWithContent(string $name, string $content, ?string $mime = null): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'valtest');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    protected function makeFileOfSize(string $name, int $sizeInKb, callable $filler): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'valtest');
        $fh = fopen($tmp, 'wb');
        $written = 0;
        $target = $sizeInKb * 1024;
        $chunk = $filler(4096);
        while ($written < $target) {
            $remaining = $target - $written;
            fwrite($fh, $remaining >= strlen($chunk) ? $chunk : substr($chunk, 0, $remaining));
            $written += min($remaining, strlen($chunk));
        }
        fclose($fh);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    protected function upload(int $meetingId, array $data): void
    {
        $files = [];
        if (isset($data['file'])) {
            $files['file'] = $data['file'];
            unset($data['file']);
        }
        $this->call('POST', '/api/meetings/' . $meetingId . '/files', $data, [], $files, [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);
    }

    public function test_upload_rejects_missing_file(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload($meeting->id, []);

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
    }

    public function test_upload_rejects_disallowed_mime(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $peHeader = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF";
        $this->upload(
            $meeting->id,
            ['file' => $this->makeFileWithContent('malware.exe', $peHeader . str_repeat("\x00", 200), 'application/x-msdownload')]
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_upload_rejects_oversize_document(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $pdfHeader = "%PDF-1.4\n";
        $this->upload(
            $meeting->id,
            ['file' => $this->makeFileOfSize('big.pdf', 21 * 1024, fn (int $n) => str_repeat('A', $n) . $pdfHeader)]
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_upload_rejects_oversize_video(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $ftyp = str_repeat("\x00\x00\x00\x18ftypmp42", 1);
        $this->upload(
            $meeting->id,
            ['file' => $this->makeFileOfSize('huge.mp4', 201 * 1024, fn (int $n) => str_pad($ftyp, $n, "\x00"))]
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_upload_accepts_audio_within_higher_limit(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $frame = "\xFF\xFB\x90\x00";
        $this->upload(
            $meeting->id,
            ['file' => $this->makeFileOfSize('song.mp3', 30 * 1024, fn (int $n) => str_repeat($frame, $n))]
        );

        $this->seeStatusCode(201);
    }

    public function test_upload_accepts_video_within_higher_limit(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $ftyp = 'ftypmp42';
        $this->upload(
            $meeting->id,
            ['file' => $this->makeFileOfSize('clip.mp4', 150 * 1024, function (int $n) use ($ftyp) {
                $prefix = str_repeat("\x00", 4) . $ftyp;
                $filler = str_repeat("\x00", max(1, $n - strlen($prefix)));

                return $prefix . $filler;
            })]
        );

        $this->seeStatusCode(201);
    }

    public function test_upload_uses_actual_mime_from_finfo(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $pePayload = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF"
            . "This program cannot be run in DOS mode.\r\n\x00"
            . str_repeat("\x00", 100);
        $this->upload(
            $meeting->id,
            ['file' => $this->makeFileWithContent('looks-like.pdf', $pePayload, 'application/pdf')]
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_upload_rejects_oversize_label(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload(
            $meeting->id,
            [
                'file' => $this->makeFileWithContent('note.pdf', '%PDF-1.4 hello', 'application/pdf'),
                'label' => str_repeat('a', 300),
            ]
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['label']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }
}
