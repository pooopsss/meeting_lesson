<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\UploadedFile;
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

    protected function makeFakeFile(string $name, string $content): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mftest');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $name, null, null, true);
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

    public function test_user_can_upload_file_to_owned_meeting(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $file = $this->makeFakeFile('note.pdf', '%PDF-1.4 hello');

        $this->postWithFile('/api/meetings/' . $meeting->id . '/files', [
            'file' => $file,
        ], $this->authHeaders());

        $this->seeStatusCode(201);
        $this->seeJsonStructure([
            'id',
            'meeting_id',
            'user_id',
            'original_name',
            'mime_type',
            'size',
        ]);

        $body = json_decode($this->response->getContent(), true);
        $this->assertEquals($meeting->id, $body['meeting_id']);
        $this->assertEquals($user->id, $body['user_id']);
        $this->assertEquals('note.pdf', $body['original_name']);
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $owner = $this->makeUser();
        $meeting = $this->makeMeeting($owner);

        $file = $this->makeFakeFile('note.pdf', '%PDF-1.4 hello');

        $this->postWithFile('/api/meetings/' . $meeting->id . '/files', [
            'file' => $file,
        ]);

        $this->seeStatusCode(401);
    }

    public function test_user_can_download_uploaded_file(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $payload = '%PDF-1.4 hello world';
        $file = $this->makeFakeFile('note.pdf', $payload);

        $this->postWithFile('/api/meetings/' . $meeting->id . '/files', [
            'file' => $file,
        ], $this->authHeaders());
        $created = json_decode($this->response->getContent(), true);

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

        $file = $this->makeFakeFile('note.pdf', 'hello');
        $this->postWithFile('/api/meetings/' . $meeting->id . '/files', ['file' => $file], $this->authHeaders());
        $created = json_decode($this->response->getContent(), true);

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $created['id']);

        $this->seeStatusCode(401);
    }
}
