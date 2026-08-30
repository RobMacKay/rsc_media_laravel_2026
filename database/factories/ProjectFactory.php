<?php

namespace Database\Factories;

use App\Enums\ProjectPhase;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
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
            'title' => rtrim(fake()->sentence(3), '.'),
            'summary' => fake()->sentence(),
            'phase' => fake()->randomElement(ProjectPhase::cases()),
            'percent' => fake()->numberBetween(0, 100),
            'milestone' => fake()->sentence(3),
            'due_on' => fake()->dateTimeBetween('now', '+2 months'),
            'waiting_on_client' => null,
            'hours_used' => fake()->numberBetween(0, 40),
            'hours_budgeted' => 55,
            'value_label' => '£'.fake()->numberBetween(1, 9).',000 fixed',
        ];
    }

    /**
     * Indicate that the project is blocked on something the client owes us.
     */
    public function waitingOnClient(string $what = 'Sign off the quote PDF layout'): static
    {
        return $this->state(fn (array $attributes) => [
            'waiting_on_client' => $what,
        ]);
    }
}
