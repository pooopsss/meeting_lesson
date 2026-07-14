<?php

namespace Tests\Feature;

use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class MeetingsTest extends TestCase
{
    use DatabaseMigrations;

    private function registerAndGetToken(string $email = 'test@example.com'): string
    {
        $this->post('/api/register', [
            'email' => $email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        return json_decode($this->response->getContent(), true)['token'];
    }

    public function test_unauthenticated_user_cannot_create_meeting(): void
    {
        $this->post('/api/meetings', [
            'title' => 'Standup',
            'scheduled_at' => '2026-07-09 09:00:00',
        ]);

        $this->seeStatusCode(401);
    }

    public function test_unauthenticated_user_cannot_list_meetings(): void
    {
        $this->get('/api/meetings');

        $this->seeStatusCode(401);
    }

    public function test_unauthenticated_user_cannot_get_meeting(): void
    {
        $this->get('/api/meetings/1');

        $this->seeStatusCode(401);
    }

    public function test_auth_with_invalid_token_returns_401(): void
    {
        $this->post('/api/meetings', [
            'title' => 'Standup',
            'scheduled_at' => '2026-07-09 09:00:00',
        ], ['Authorization' => 'Bearer invalid-token']);

        $this->seeStatusCode(401);
    }

    public function test_create_meeting_with_valid_data(): void
    {
        $token = $this->registerAndGetToken();

        $this->post('/api/meetings', [
            'title' => 'Daily Standup',
            'description' => 'Team sync meeting',
            'scheduled_at' => '2026-07-09 09:00:00',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(201);
        $this->seeJsonStructure([
            'id',
            'title',
            'description',
            'scheduled_at',
            'user_id',
        ]);

        $body = json_decode($this->response->getContent(), true);
        $this->assertEquals('Daily Standup', $body['title']);
        $this->assertEquals('Team sync meeting', $body['description']);
        $this->assertEquals('2026-07-09 09:00:00', $body['scheduled_at']);
    }

    public function test_create_meeting_requires_title(): void
    {
        $token = $this->registerAndGetToken();

        $this->post('/api/meetings', [
            'scheduled_at' => '2026-07-09 09:00:00',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['title']]);
    }

    public function test_create_meeting_requires_scheduled_at(): void
    {
        $token = $this->registerAndGetToken();

        $this->post('/api/meetings', [
            'title' => 'Standup',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(422);
        $this->seeJsonStructure(['errors' => ['scheduled_at']]);
    }

    public function test_list_meetings_returns_empty_list(): void
    {
        $token = $this->registerAndGetToken();

        $this->get('/api/meetings', ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(200);

        $body = json_decode($this->response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertEmpty($body);
    }

    public function test_list_meetings_returns_created_meetings(): void
    {
        $token = $this->registerAndGetToken();

        $this->post('/api/meetings', [
            'title' => 'Meeting 1',
            'scheduled_at' => '2026-07-09 09:00:00',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->post('/api/meetings', [
            'title' => 'Meeting 2',
            'scheduled_at' => '2026-07-10 10:00:00',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->get('/api/meetings', ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(200);
        $body = json_decode($this->response->getContent(), true);
        $this->assertCount(2, $body);
        $this->assertEquals('Meeting 1', $body[0]['title']);
        $this->assertEquals('Meeting 2', $body[1]['title']);
    }

    public function test_list_meetings_only_returns_own_meetings(): void
    {
        $tokenA = $this->registerAndGetToken('userA@example.com');
        $tokenB = $this->registerAndGetToken('userB@example.com');

        $this->post('/api/meetings', [
            'title' => 'User A Meeting',
            'scheduled_at' => '2026-07-09 09:00:00',
        ], ['Authorization' => 'Bearer ' . $tokenA]);

        $this->post('/api/meetings', [
            'title' => 'User B Meeting',
            'scheduled_at' => '2026-07-10 10:00:00',
        ], ['Authorization' => 'Bearer ' . $tokenB]);

        $this->get('/api/meetings', ['Authorization' => 'Bearer ' . $tokenA]);

        $this->seeStatusCode(200);
        $body = json_decode($this->response->getContent(), true);
        $this->assertCount(1, $body);
        $this->assertEquals('User A Meeting', $body[0]['title']);
    }

    public function test_get_meeting_by_id(): void
    {
        $token = $this->registerAndGetToken();

        $this->post('/api/meetings', [
            'title' => 'Standup',
            'scheduled_at' => '2026-07-09 09:00:00',
        ], ['Authorization' => 'Bearer ' . $token]);

        $created = json_decode($this->response->getContent(), true);

        $this->get('/api/meetings/' . $created['id'], ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(200);
        $body = json_decode($this->response->getContent(), true);
        $this->assertEquals($created['id'], $body['id']);
        $this->assertEquals('Standup', $body['title']);
    }

    public function test_get_nonexistent_meeting_returns_404(): void
    {
        $token = $this->registerAndGetToken();

        $this->get('/api/meetings/99999', ['Authorization' => 'Bearer ' . $token]);

        $this->seeStatusCode(404);
    }
}
