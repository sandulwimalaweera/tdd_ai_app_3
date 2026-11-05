<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::Pending,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => TaskStatus::InProgress]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => TaskStatus::Completed]);
    }
}
