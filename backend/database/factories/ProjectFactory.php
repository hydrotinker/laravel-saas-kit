<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->catchPhrase(),
            'description' => fake()->optional()->sentence(),
            'status' => ProjectStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Archived]);
    }
}
