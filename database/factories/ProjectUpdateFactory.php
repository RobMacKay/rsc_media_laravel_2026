<?php

namespace Database\Factories;

use App\Enums\UpdateKind;
use App\Models\ProjectUpdate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectUpdate>
 */
class ProjectUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'kind' => UpdateKind::Project,
            'tag' => fake()->word(),
            'title' => fake()->sentence(5),
            'body' => fake()->paragraph(),
            'published_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Indicate that the update is studio news rather than project progress.
     */
    public function studio(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => UpdateKind::Studio,
            'tag' => 'studio',
        ]);
    }
}
