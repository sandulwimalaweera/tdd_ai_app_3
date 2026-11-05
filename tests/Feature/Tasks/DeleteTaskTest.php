<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_permanently_deletes_only_the_requested_task_without_authentication(): void
    {
        $task = Task::factory()->create();
        $otherTask = Task::factory()->create();

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertNoContent();
        $this->assertModelMissing($task);
        $this->assertModelExists($otherTask);
    }

    public function test_deleting_a_previously_deleted_task_returns_404(): void
    {
        $task = Task::factory()->create();
        $task->delete();

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertNotFound();
        $this->assertDatabaseCount('tasks', 0);
    }

    #[DataProvider('missingTaskIds')]
    public function test_deleting_a_missing_or_malformed_uuid_returns_404(string $id): void
    {
        $response = $this->deleteJson("/api/tasks/{$id}");

        $response->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingTaskIds(): array
    {
        return [
            'missing UUID' => ['019a2f53-7c00-7000-8000-000000000001'],
            'malformed UUID' => ['not-a-uuid'],
        ];
    }
}
