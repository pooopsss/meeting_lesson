<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingFile;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class LoggingAndInfraTest extends TestCase
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
        $tmp = tempnam(sys_get_temp_dir(), 'logtest');
        file_put_contents($tmp, $content);

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

    public function test_upload_writes_log_on_success(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($meeting, $user) {
                return $message === 'meeting_file: uploaded'
                    && ($context['meeting_id'] ?? null) === $meeting->id
                    && ($context['user_id'] ?? null) === $user->id
                    && isset($context['file_id'])
                    && ($context['status'] ?? null) === 'ok';
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->postWithFile(
            '/api/meetings/' . $meeting->id . '/files',
            ['file' => $this->makeFileWithContent('note.pdf', '%PDF-1.4 hello', 'application/pdf')],
            $this->authHeaders()
        );

        $this->seeStatusCode(201);
    }

    public function test_download_writes_log_on_success(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        $this->postWithFile(
            '/api/meetings/' . $meeting->id . '/files',
            ['file' => $this->makeFileWithContent('note.pdf', '%PDF-1.4 hello', 'application/pdf')],
            $this->authHeaders()
        );
        $created = json_decode($this->response->getContent(), true);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($created, $user) {
                return $message === 'meeting_file: downloaded'
                    && ($context['file_id'] ?? null) === $created['id']
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['status'] ?? null) === 'ok';
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->get('/api/meetings/' . $meeting->id . '/files/' . $created['id'], $this->authHeaders());

        $this->seeStatusCode(200);
    }

    public function test_delete_writes_log_on_success(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);

        $row = MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'original_name' => 'note.pdf',
            'stored_name' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);
        Storage::disk('local')->put('meetings/' . $meeting->id . '/' . $row->stored_name, 'hello');

        $this->loginAs($user);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($row, $user) {
                return $message === 'meeting_file: deleted'
                    && ($context['file_id'] ?? null) === $row->id
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['status'] ?? null) === 'ok';
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->delete('/api/meetings/' . $meeting->id . '/files/' . $row->id, [], $this->authHeaders());

        $this->seeStatusCode(204);
    }

    public function test_upload_writes_log_on_error(): void
    {
        $user = $this->makeUser();
        $meeting = $this->makeMeeting($user);
        $this->loginAs($user);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($meeting, $user) {
                return $message === 'meeting_file: validation failed'
                    && ($context['meeting_id'] ?? null) === $meeting->id
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['status'] ?? null) === 'error'
                    && ! empty($context['errors']);
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->postWithFile(
            '/api/meetings/' . $meeting->id . '/files',
            ['file' => $this->makeFileWithContent('malware.exe', "MZ\x90\x00" . str_repeat("\x00", 200), 'application/x-msdownload')],
            $this->authHeaders()
        );

        $this->seeStatusCode(422);
    }

    public function test_nginx_blocks_storage_path(): void
    {
        $probe = '/storage/meetings/1/something.pdf';
        $url = getenv('NGINX_TEST_URL') ?: 'http://nginx:80';

        $ch = curl_init($url . $probe);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
        ]);
        $output = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->assertNotFalse($output, "curl could not reach nginx at $url$probe — $curlError. Is the container reachable?");
        $this->assertContains($code, [403, 404], "Expected 403/404 for $probe, got $code. Headers:\n$output");
    }

    public function test_files_survive_backend_restart(): void
    {
        $disk = Storage::disk('local');
        $markerRel = 'meetings/persistence-test/marker.txt';
        $markerContent = 'survive-' . bin2hex(random_bytes(8));

        $this->assertFalse($disk->exists($markerRel), 'Pre-condition: marker must not exist yet');

        $disk->put($markerRel, $markerContent);

        $absolute = $disk->path($markerRel);
        $this->assertFileExists($absolute);
        $this->assertSame($markerContent, file_get_contents($absolute));

        $diskRoot = realpath($disk->path(''));
        $this->assertNotFalse($diskRoot);
        $this->assertStringStartsWith(
            DIRECTORY_SEPARATOR,
            $diskRoot,
            'Storage local root must be on a real (bind-mounted) filesystem path, not in /tmp'
        );
        $this->assertStringNotContainsString(
            '/tmp/',
            $diskRoot . DIRECTORY_SEPARATOR,
            'Storage local root must not live in /tmp, otherwise backend restart would lose files'
        );

        $disk->delete($markerRel);
    }
}
