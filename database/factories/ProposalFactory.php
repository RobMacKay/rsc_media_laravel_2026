<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'PRJ-'.fake()->unique()->numberBetween(100, 999),
            'team_id' => Team::factory(),
            'title' => rtrim(fake()->sentence(3), '.'),
            'brief' => fake()->paragraph(),
            'goal' => fake()->sentence(),
            'budget_guide' => fake()->randomElement(Proposal::budgets(Currency::Base)),
            'needed_by' => 'before the October rush',
            'status' => ProposalStatus::Submitted,
            'deposit_percent' => 40,
            'weeks' => 4,
        ];
    }

    /**
     * Indicate that the studio has written the proposal up and sent it out.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProposalStatus::Sent,
            'sent_at' => now()->subDays(3),
            'scope' => "First deliverable\nSecond deliverable",
            'phases' => "Scoping | 18 Aug | Field list agreed.\nBuild | 1 Sep | The actual work.",
            'excluded' => 'Photography.',
            'price' => 3400,
        ]);
    }
}
