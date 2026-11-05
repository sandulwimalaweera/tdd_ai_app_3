<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListTasksTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_an_empty_paginated_list_without_authentication(): void
    {
        $response = $this->getJson('/api/tasks');

        $response->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);
    }

    public function test_lists_fifteen_tasks_per_page_by_default(): void
    {
        Task::factory()->count(16)->create();

        $response = $this->getJson('/api/tasks');

        $response->assertOk()->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2);
    }

    #[DataProvider('orderedPages')]
    public function test_pagination_orders_by_creation_time_and_uuid(int $page, array $ids): void
    {
        Task::factory()->create(['id' => '019a2f53-7c00-7000-8000-000000000001', 'created_at' => '2026-11-04 06:00:00']);
        Task::factory()->create(['id' => '019a2f53-7c00-7000-8000-000000000002', 'created_at' => '2026-11-04 06:00:00']);
        Task::factory()->create(['id' => '019a2f53-7c00-7000-8000-000000000003', 'created_at' => '2026-11-05 06:00:00']);

        $response = $this->getJson("/api/tasks?per_page=2&page={$page}");

        $response->assertOk()->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.total', 3);
        $this->assertSame($ids, $response->json('data.*.id'));
        $this->assertStringContainsString('per_page=2', $response->json('links.first'));
    }

    public function test_accepts_the_maximum_page_size(): void
    {
        $response = $this->getJson('/api/tasks?per_page=100');

        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_returns_422(string $query, string $field, string $message): void
    {
        $response = $this->getJson("/api/tasks?{$query}");

        $response->assertUnprocessable()->assertJsonValidationErrors([$field])
            ->assertJsonPath("errors.{$field}.0", $message);
    }

    /**
     * @return array<string, array{int, list<string>}>
     */
    public static function orderedPages(): array
    {
        return [
            'first page' => [1, ['019a2f53-7c00-7000-8000-000000000003', '019a2f53-7c00-7000-8000-000000000002']],
            'second page' => [2, ['019a2f53-7c00-7000-8000-000000000001']],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidPagination(): array
    {
        return [
            'non-integer page' => ['page=abc', 'page', 'The page field must be an integer.'],
            'zero page' => ['page=0', 'page', 'The page field must be at least 1.'],
            'non-integer size' => ['per_page=1.5', 'per_page', 'The per page field must be an integer.'],
            'zero size' => ['per_page=0', 'per_page', 'The per page field must be at least 1.'],
            'oversized page' => ['per_page=101', 'per_page', 'The per page field must not be greater than 100.'],
        ];
    }
}
