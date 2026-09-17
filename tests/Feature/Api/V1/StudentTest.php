<?php

namespace Tests\Feature\Api\V1;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    public function test_a_student_can_be_created(): void
    {
        $data = $this->register()->json('data');

        $response = $this->authRequest($data['access_token'], 'POST', '/api/v1/students', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'notes' => 'First student',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Alice')
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('students', ['name' => 'Alice']);
    }

    public function test_students_are_listed_with_cursor_pagination(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        foreach (['Alice', 'Bob', 'Cara'] as $name) {
            $this->authRequest($token, 'POST', '/api/v1/students', ['name' => $name])->assertCreated();
        }

        $page = $this->authRequest($token, 'GET', '/api/v1/students?per_page=2');

        $page->assertOk()
            ->assertJsonPath('data.has_more', true)
            ->assertJsonCount(2, 'data.items');

        $nextCursor = $page->json('data.next_cursor');
        $this->assertNotNull($nextCursor);

        $this->authRequest($token, 'GET', '/api/v1/students?per_page=2&cursor='.$nextCursor)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_a_student_can_be_updated_with_the_matching_version(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $student = $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->json('data');

        $this->authRequest($token, 'PUT', '/api/v1/students/'.$student['id'], [
            'name' => 'Alice Updated',
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Alice Updated')
            ->assertJsonPath('data.version', 2);
    }

    public function test_a_stale_version_update_conflicts(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $student = $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->json('data');

        // Move the server to version 2.
        $this->authRequest($token, 'PUT', '/api/v1/students/'.$student['id'], [
            'name' => 'Alice v2',
            'version' => 1,
        ])->assertOk();

        // A client still holding version 1 must not silently clobber it.
        $this->authRequest($token, 'PUT', '/api/v1/students/'.$student['id'], [
            'name' => 'Alice v1 retry',
            'version' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'SYNC_CONFLICT')
            ->assertJsonPath('data.server_version', 2);
    }

    public function test_a_student_can_be_deleted_with_the_matching_version(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $student = $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->json('data');

        $this->authRequest($token, 'DELETE', '/api/v1/students/'.$student['id'], ['version' => 1])
            ->assertOk();

        $this->assertSoftDeleted('students', ['id' => $student['id']]);

        $this->authRequest($token, 'GET', '/api/v1/students/'.$student['id'])
            ->assertStatus(404);
    }

    public function test_student_creation_requires_a_name(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/students', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_user_cannot_view_another_users_student(): void
    {
        $this->register()->json('data');
        $student = Student::factory()->create(['user_id' => 1]);

        $this->forgetAuthGuard();

        $intruder = $this->register(['email' => 'intruder@example.com'])->json('data');

        $this->authRequest($intruder['access_token'], 'GET', '/api/v1/students/'.$student->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }
}
