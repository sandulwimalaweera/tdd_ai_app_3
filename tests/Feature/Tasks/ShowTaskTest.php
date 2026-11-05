<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_the_requested_task_resource_without_authentication(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 06:00:00', 'UTC'));
        $task = Task::factory()->completed()->create(['title' => 'Write journal', 'description' => null]);
        Task::factory()->create();

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $task->id,
                'title' => 'Write journal',
                'description' => null,
                'status' => 'completed',
                'created_at' => '2026-11-05T06:00:00.000000Z',
                'updated_at' => '2026-11-05T06:00:00.000000Z',
            ],
        ]);
    }

    #[DataProvider('missingTaskIds')]
    public function test_returns_404_for_a_missing_or_invalid_task_id(string $id): void
    {
        $response = $this->getJson("/api/tasks/{$id}");

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
            'integer ID' => ['1'],
        ];
    }
}
