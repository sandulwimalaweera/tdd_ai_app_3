<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_pending_task_with_a_uuid_without_authentication(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 06:00:00', 'UTC'));

        $response = $this->postJson('/api/tasks', ['title' => 'Plan tomorrow']);

        $response->assertCreated()->assertExactJson([
            'data' => [
                'id' => $response->json('data.id'),
                'title' => 'Plan tomorrow',
                'description' => null,
                'status' => 'pending',
                'created_at' => '2026-11-05T06:00:00.000000Z',
                'updated_at' => '2026-11-05T06:00:00.000000Z',
            ],
        ]);
        $this->assertTrue(Str::isUuid($response->json('data.id'), version: 7));
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'title' => 'Plan tomorrow',
            'description' => null,
            'status' => 'pending',
        ]);
    }

    #[DataProvider('taskStatuses')]
    public function test_creates_a_task_with_a_description_and_valid_status(string $status): void
    {
        $payload = ['title' => 'Focus session', 'description' => 'Work without interruptions.', 'status' => $status];

        $response = $this->postJson('/api/tasks', $payload);

        $response->assertCreated()->assertJsonFragment($payload);
        $this->assertDatabaseHas('tasks', $payload);
    }

    public function test_accepts_the_maximum_title_and_description_lengths(): void
    {
        $payload = ['title' => str_repeat('a', 191), 'description' => str_repeat('b', 10000)];

        $response = $this->postJson('/api/tasks', $payload);

        $response->assertCreated()->assertJsonFragment($payload);
        $this->assertDatabaseHas('tasks', $payload);
    }

    public function test_separate_tasks_receive_distinct_uuid_primary_keys(): void
    {
        $firstTask = Task::factory()->create();

        $response = $this->postJson('/api/tasks', ['title' => 'Another task']);

        $response->assertCreated();
        $this->assertTrue(Str::isUuid($response->json('data.id'), version: 7));
        $this->assertNotSame($firstTask->id, $response->json('data.id'));
        $this->assertDatabaseCount('tasks', 2);
    }

    #[DataProvider('invalidTasks')]
    public function test_invalid_input_returns_422_without_creating_a_task(array $payload, string $field, string $message): void
    {
        $response = $this->postJson('/api/tasks', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $response->assertJsonPath("errors.{$field}.0", $message);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function taskStatuses(): array
    {
        return ['pending' => ['pending'], 'in progress' => ['in_progress'], 'completed' => ['completed']];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidTasks(): array
    {
        return [
            'missing title' => [[], 'title', 'The title field is required.'],
            'blank title' => [['title' => '   '], 'title', 'The title field is required.'],
            'non-string title' => [['title' => 12], 'title', 'The title field must be a string.'],
            'long title' => [['title' => str_repeat('a', 192)], 'title', 'The title field must not be greater than 191 characters.'],
            'non-string description' => [['title' => 'Task', 'description' => []], 'description', 'The description field must be a string.'],
            'long description' => [['title' => 'Task', 'description' => str_repeat('a', 10001)], 'description', 'The description field must not be greater than 10000 characters.'],
            'unknown status' => [['title' => 'Task', 'status' => 'cancelled'], 'status', 'The selected status is invalid.'],
            'null status' => [['title' => 'Task', 'status' => null], 'status', 'The status field is required.'],
            'non-string status' => [['title' => 'Task', 'status' => []], 'status', 'The status field is required.'],
            'client ID' => [['title' => 'Task', 'id' => '019a2f53-7c00-7000-8000-000000000001'], 'id', 'The id field is prohibited.'],
            'client creation date' => [['title' => 'Task', 'created_at' => '2000-01-01'], 'created_at', 'The created at field is prohibited.'],
            'client update date' => [['title' => 'Task', 'updated_at' => '2000-01-01'], 'updated_at', 'The updated at field is prohibited.'],
        ];
    }
}
