<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'tenant_id' => fn (array $attributes) => Project::find($attributes['project_id'])->tenant_id,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::Todo,
            'assignee_id' => null,
            'due_date' => fake()->optional()->dateTimeBetween('now', '+1 month'),
        ];
    }
}
