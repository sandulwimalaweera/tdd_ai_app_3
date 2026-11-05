<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_patch_preserves_omitted_fields_and_the_uuid_without_authentication(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 06:00:00', 'UTC'));
        $task = Task::factory()->inProgress()->create(['title' => 'Old title', 'description' => 'Keep this description.']);
        $this->travelTo(Carbon::parse('2026-11-05 07:00:00', 'UTC'));

        $response = $this->patchJson("/api/tasks/{$task->id}", ['title' => 'Updated title']);

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $task->id,
                'title' => 'Updated title',
                'description' => 'Keep this description.',
                'status' => 'in_progress',
                'created_at' => '2026-11-05T06:00:00.000000Z',
                'updated_at' => '2026-11-05T07:00:00.000000Z',
            ],
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id, 'title' => 'Updated title', 'description' => 'Keep this description.', 'status' => 'in_progress',
        ]);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_patch_can_clear_the_description_without_changing_other_fields(): void
    {
        $task = Task::factory()->create(['title' => 'Keep title', 'description' => 'Remove this.']);

        $response = $this->patchJson("/api/tasks/{$task->id}", ['description' => null]);

        $response->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.title', 'Keep title');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'description' => null, 'title' => 'Keep title', 'status' => 'pending']);
    }

    #[DataProvider('taskStatuses')]
    public function test_patch_accepts_each_task_status(string $status): void
    {
        $task = Task::factory()->completed()->create();

        $response = $this->patchJson("/api/tasks/{$task->id}", ['status' => $status]);

        $response->assertOk()->assertJsonPath('data.status', $status);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => $status]);
    }

    public function test_put_replaces_all_writable_fields_and_preserves_the_uuid(): void
    {
        $task = Task::factory()->create(['description' => 'Old description']);
        $payload = ['title' => 'Replacement title', 'description' => null, 'status' => 'completed'];

        $response = $this->putJson("/api/tasks/{$task->id}", $payload);

        $response->assertOk()->assertJsonFragment($payload)->assertJsonPath('data.id', $task->id);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, ...$payload]);
        $this->assertDatabaseCount('tasks', 1);
    }

    #[DataProvider('missingPutFields')]
    public function test_put_requires_every_writable_field(string $field, string $message): void
    {
        $task = Task::factory()->create();
        $before = $task->fresh()->getAttributes();
        $payload = ['title' => 'Replacement', 'description' => null, 'status' => 'completed'];
        unset($payload[$field]);

        $response = $this->putJson("/api/tasks/{$task->id}", $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$field])->assertJsonPath("errors.{$field}.0", $message);
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    #[DataProvider('invalidPatches')]
    public function test_invalid_patch_returns_422_and_leaves_the_task_unchanged(array $payload, string $field, string $message): void
    {
        $task = Task::factory()->create();
        $before = $task->fresh()->getAttributes();

        $response = $this->patchJson("/api/tasks/{$task->id}", $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$field])->assertJsonPath("errors.{$field}.0", $message);
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    #[DataProvider('missingTaskUpdates')]
    public function test_updates_return_404_for_missing_or_malformed_uuid(string $method, string $id): void
    {
        $payload = ['title' => 'Updated', 'description' => null, 'status' => 'pending'];

        $response = $this->json($method, "/api/tasks/{$id}", $payload);

        $response->assertNotFound();
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
     * @return array<string, array{string, string}>
     */
    public static function missingPutFields(): array
    {
        return [
            'title' => ['title', 'The title field is required.'],
            'description' => ['description', 'The description field must be present.'],
            'status' => ['status', 'The status field is required.'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidPatches(): array
    {
        return [
            'empty patch' => [[], 'task', 'Provide at least one task field to update.'],
            'unknown fields only' => [['extra' => 'ignored'], 'task', 'Provide at least one task field to update.'],
            'blank title' => [['title' => '   '], 'title', 'The title field is required.'],
            'null title' => [['title' => null], 'title', 'The title field is required.'],
            'non-string title' => [['title' => 12], 'title', 'The title field must be a string.'],
            'long title' => [['title' => str_repeat('a', 192)], 'title', 'The title field must not be greater than 191 characters.'],
            'non-string description' => [['description' => []], 'description', 'The description field must be a string.'],
            'long description' => [['description' => str_repeat('a', 10001)], 'description', 'The description field must not be greater than 10000 characters.'],
            'unknown status' => [['status' => 'cancelled'], 'status', 'The selected status is invalid.'],
            'null status' => [['status' => null], 'status', 'The status field is required.'],
            'client ID' => [['id' => '019a2f53-7c00-7000-8000-000000000001'], 'id', 'The id field is prohibited.'],
            'client creation date' => [['created_at' => '2000-01-01'], 'created_at', 'The created at field is prohibited.'],
            'client update date' => [['updated_at' => '2000-01-01'], 'updated_at', 'The updated at field is prohibited.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function missingTaskUpdates(): array
    {
        return [
            'patch missing UUID' => ['PATCH', '019a2f53-7c00-7000-8000-000000000001'],
            'patch malformed UUID' => ['PATCH', 'not-a-uuid'],
            'put missing UUID' => ['PUT', '019a2f53-7c00-7000-8000-000000000001'],
            'put malformed UUID' => ['PUT', 'not-a-uuid'],
        ];
    }
}
