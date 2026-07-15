<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class MeetingFilesTest extends TestCase
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

    protected function makeMeeting(User $owner, array $overrides = []): Meeting
    {
        return Meeting::create(array_merge([
            'user_id' => $owner->id,
            'title' => 'Standup',
            'description' => null,
            'scheduled_at' => '2026-07-15 09:00:00',
        ], $overrides));
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

    protected function makeFakeFile(string $name, string $content, ?string $mime = null): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mftest');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    protected function makeFakeAudio(string $name, int $sizeInKb, string $mime = 'audio/mpeg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mftest');
        $targetBytes = $sizeInKb * 1024;
        $frame = "\xFF\xFB\x90\x00";
        $frameLen = strlen($frame);
        $fh = fopen($tmp, 'wb');
        $written = 0;
        $chunk = str_repeat($frame, 4096);
        while ($written < $targetBytes) {
            $remaining = $targetBytes - $written;
            fwrite($fh, $remaining >= strlen($chunk) ? $chunk : substr($chunk, 0, $remaining));
            $written += min($remaining, strlen($chunk));
        }
        fclose($fh);

        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    protected function postWithFile(string $uri, array $data, array $headers = []): void
    {
        $files = [];
        if (isset($data['file'])) {
            $files['file'] = $data['file'];
            unset($data['file']);
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtr(strtoupper($name), '-', '_');
            $server[$key] = $value;
        }

        $this->call('POST', $uri, $data, [], $files, $server);
    }

    protected function upload(string $meetingId, UploadedFile $file, array $extra = []): array
    {
        $this->postWithFile(
            '/api/meetings/' . $meetingId . '/files',
            array_merge(['file' => $file], $extra),
            $this->authHeaders()
        );

        return json_decode($this->response->getContent(), true) ?? [];
    }

    public function test_user_can_upload_file_to_owned_meeting(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $body = $this->upload($meeting->id, $this->makeFakeFile('note.pdf', '%PDF-1.4 hello'));

        $this->seeStatusCode(201);
        $this->seeJsonStructure([
            'id',
            'meeting_id',
            'user_id',
            'original_name',
            'mime_type',
            'size',
        ]);
        $this->assertEquals($meeting->id, $body['meeting_id']);
        $this->assertEquals($user->id, $body['user_id']);
        $this->assertEquals('note.pdf', $body['original_name']);
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $owner = $this->makeUser();
        $meeting = $this->makeMeeting($owner);

        $this->postWithFile(
            '/api/meetings/' . $meeting->id . '/files',
            ['file' => $this->makeFakeFile('note.pdf', '%PDF-1.4 hello')]
        );

        $this->seeStatusCode(401);
    }

    public function test_user_can_download_uploaded_file(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $payload = '%PDF-1.4 hello world';
        $created = $this->upload($meeting->id, $this->makeFakeFile('note.pdf', $payload));

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $created['id'], $this->authHeaders());

        $this->seeStatusCode(200);
        $this->assertEquals(
            $payload,
            file_get_contents(Storage::disk('local')->path('meetings/' . $meeting->id . '/' . $created['stored_name']))
        );
        $disposition = $this->response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('note.pdf', $disposition);
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        $owner = $this->makeUser();
        $meeting = $this->makeMeeting($owner);
        $this->loginAs($owner);

        $created = $this->upload($meeting->id, $this->makeFakeFile('note.pdf', 'hello'));

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $created['id']);

        $this->seeStatusCode(401);
    }

    public function test_upload_to_nonexistent_meeting_returns_404(): void
    {
        $this->makeUser();
        $user = $this->makeUser('other@example.com');
        $this->loginAs($user);

        $this->postWithFile(
            '/api/meetings/999999/files',
            ['file' => $this->makeFakeFile('note.pdf', '%PDF-1.4 hello')],
            $this->authHeaders()
        );

        $this->seeStatusCode(404);
    }

    public function test_upload_to_other_users_meeting_returns_404(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $meeting = $this->makeMeeting($owner);
        $intruder = $this->makeUser('intruder@example.com');
        $this->loginAs($intruder);

        $this->postWithFile(
            '/api/meetings/' . $meeting->id . '/files',
            ['file' => $this->makeFakeFile('note.pdf', '%PDF-1.4 hello')],
            $this->authHeaders()
        );

        $this->seeStatusCode(404);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_download_of_other_users_file_returns_404(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $meeting = $this->makeMeeting($owner);
        $this->loginAs($owner);
        $created = $this->upload($meeting->id, $this->makeFakeFile('note.pdf', '%PDF-1.4 hello'));

        $intruder = $this->makeUser('intruder@example.com');
        $this->loginAs($intruder);

        $this->get(
            '/api/meetings/' . $meeting->id . '/files/' . $created['id'],
            $this->authHeaders()
        );

        $this->seeStatusCode(404);
    }

    public function test_download_of_nonexistent_file_returns_404(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->get('/api/meetings/' . $meeting->id . '/files/999999', $this->authHeaders());

        $this->seeStatusCode(404);
    }

    public function test_missing_file_field_returns_422(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->post('/api/meetings/' . $meeting->id . '/files', [], $this->authHeaders());

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
    }

    public function test_label_too_long_returns_422(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload(
            $meeting->id,
            $this->makeFakeFile('note.pdf', '%PDF-1.4 hello'),
            ['label' => str_repeat('a', 256)]
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['label']]);
    }

    public function test_disallowed_mime_returns_422_and_does_not_write_file(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload(
            $meeting->id,
            UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload')
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_file_exceeding_size_limit_returns_422(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload(
            $meeting->id,
            UploadedFile::fake()->create('big.pdf', 20 * 1024 + 1, 'application/pdf')
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
        $this->assertCount(0, Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_audio_at_size_above_document_limit_is_allowed(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload(
            $meeting->id,
            $this->makeFakeAudio('big.mp3', 21 * 1024)
        );

        $this->seeStatusCode(201);
    }

    public function test_audio_at_size_above_audio_limit_is_rejected(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->upload(
            $meeting->id,
            $this->makeFakeAudio('huge.mp3', 201 * 1024)
        );

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['file']]);
    }

    public function test_path_traversal_in_stored_name_returns_404(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $row = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => 'evil.pdf',
            'stored_name' => '../escape.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $row->id, $this->authHeaders());

        $this->seeStatusCode(404);
    }

    public function test_filename_with_newline_is_sanitized(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $body = $this->upload(
            $meeting->id,
            $this->makeFakeFile("evil\"\r\nX-Evil: 1.pdf", '%PDF-1.4 hello')
        );

        $this->seeStatusCode(201);
        $this->assertStringNotContainsString("\r", $body['original_name']);
        $this->assertStringNotContainsString("\n", $body['original_name']);
        $this->assertStringNotContainsString('"', $body['original_name']);
    }

    public function test_downloaded_content_disposition_has_no_header_injection(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $body = $this->upload(
            $meeting->id,
            $this->makeFakeFile("evil\r\nX-Evil: 1.pdf", '%PDF-1.4 hello')
        );

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $body['id'], $this->authHeaders());

        $this->seeStatusCode(200);
        $headers = $this->response->headers->all();
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString("\r", (string) $value);
                $this->assertStringNotContainsString("\n", (string) $value);
            }
        }
    }

    public function test_db_write_failure_rolls_back_disk_file(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        Event::listen('eloquent.creating: ' . \App\Models\MeetingFile::class, function () {
            throw new \RuntimeException('simulated db failure');
        });

        $this->upload($meeting->id, $this->makeFakeFile('note.pdf', '%PDF-1.4 hello'));

        $this->seeStatusCode(500);
        $this->assertEquals([], Storage::disk('local')->allFiles('meetings/' . $meeting->id));
    }

    public function test_user_can_list_files_of_owned_meeting_sorted_desc_by_created_at(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $old = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => 'old.pdf',
            'stored_name' => '11111111-1111-1111-1111-111111111111.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);
        $old->created_at = '2026-07-15 09:00:00';
        $old->save();

        $middle = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => 'middle.pdf',
            'stored_name' => '22222222-2222-2222-2222-222222222222.pdf',
            'mime_type' => 'application/pdf',
            'size' => 20,
        ]);
        $middle->created_at = '2026-07-15 10:00:00';
        $middle->save();

        $new = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => 'new.pdf',
            'stored_name' => '33333333-3333-3333-3333-333333333333.pdf',
            'mime_type' => 'application/pdf',
            'size' => 30,
        ]);
        $new->created_at = '2026-07-15 11:00:00';
        $new->save();

        $this->get('/api/meetings/' . $meeting->id . '/files', $this->authHeaders());

        $this->seeStatusCode(200);
        $list = json_decode($this->response->getContent(), true) ?? [];
        $this->assertCount(3, $list);
        $this->assertEquals('new.pdf', $list[0]['original_name']);
        $this->assertEquals('middle.pdf', $list[1]['original_name']);
        $this->assertEquals('old.pdf', $list[2]['original_name']);
    }

    public function test_list_files_of_nonexistent_meeting_returns_404(): void
    {
        $user = $this->makeUser();
        $this->loginAs($user);

        $this->get('/api/meetings/999999/files', $this->authHeaders());

        $this->seeStatusCode(404);
    }

    public function test_list_files_of_other_users_meeting_returns_404(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $meeting = $this->makeMeeting($owner);
        $intruder = $this->makeUser('intruder@example.com');
        $this->loginAs($intruder);

        $this->get('/api/meetings/' . $meeting->id . '/files', $this->authHeaders());

        $this->seeStatusCode(404);
    }

    public function test_uploader_can_delete_file(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $row = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => 'note.pdf',
            'stored_name' => '44444444-4444-4444-4444-444444444444.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);
        Storage::disk('local')->put('meetings/' . $meeting->id . '/' . $row->stored_name, '%PDF-1.4 hello');

        $this->delete('/api/meetings/' . $meeting->id . '/files/' . $row->id, [], $this->authHeaders());

        $this->seeStatusCode(204);
        $this->assertNull(\App\Models\MeetingFile::find($row->id));
        $this->assertFileDoesNotExist(
            Storage::disk('local')->path('meetings/' . $meeting->id . '/' . $row->stored_name)
        );
    }

    public function test_non_uploader_cannot_delete_file(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $meeting = $this->makeMeeting($owner);
        $uploader = $this->makeUser('uploader@example.com');
        $row = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $uploader->id,
            'original_name' => 'note.pdf',
            'stored_name' => '55555555-5555-5555-5555-555555555555.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);
        Storage::disk('local')->put('meetings/' . $meeting->id . '/' . $row->stored_name, '%PDF-1.4 hello');

        $other = $this->makeUser('other@example.com');
        $this->loginAs($other);

        $this->delete('/api/meetings/' . $meeting->id . '/files/' . $row->id, [], $this->authHeaders());

        $this->assertContains(
            (int) $this->response->getStatusCode(),
            [403, 404],
            'Non-uploader should not be allowed to delete (got ' . $this->response->getStatusCode() . ')'
        );
        $this->assertNotNull(\App\Models\MeetingFile::find($row->id));
    }

    public function test_unauthenticated_list_and_delete_are_rejected(): void
    {
        $owner = $this->makeUser();
        $meeting = $this->makeMeeting($owner);
        $row = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $owner->id,
            'original_name' => 'note.pdf',
            'stored_name' => '66666666-6666-6666-6666-666666666666.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);

        $this->get('/api/meetings/' . $meeting->id . '/files');
        $this->seeStatusCode(401);

        $this->delete('/api/meetings/' . $meeting->id . '/files/' . $row->id);
        $this->seeStatusCode(401);

        $this->assertNotNull(\App\Models\MeetingFile::find($row->id));
    }

    public function test_path_traversal_in_original_name_is_sanitized(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $row = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => '..\\..\\windows\\system.pdf',
            'stored_name' => '77777777-7777-7777-7777-777777777777.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);
        Storage::disk('local')->put('meetings/' . $meeting->id . '/' . $row->stored_name, '%PDF-1.4 hello');

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $row->id, $this->authHeaders());

        $this->seeStatusCode(200);
        $disposition = $this->response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringNotContainsString('\\', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringContainsString('system.pdf', $disposition);
    }
}
