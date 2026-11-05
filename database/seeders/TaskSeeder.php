<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Task::factory()->count(10)->sequence(
            ['status' => TaskStatus::Pending],
            ['status' => TaskStatus::InProgress],
            ['status' => TaskStatus::Completed],
        )->create();
    }
}
