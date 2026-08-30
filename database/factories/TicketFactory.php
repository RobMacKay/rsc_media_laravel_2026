<?php

namespace Database\Factories;

use App\Enums\BillingMode;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Team;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'RSC-'.fake()->unique()->numberBetween(1001, 9999),
            'team_id' => Team::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'system' => fake()->domainName(),
            'page_url' => '/'.fake()->slug(),
            'type' => fake()->randomElement(TicketType::cases()),
            'priority' => TicketPriority::Normal,
            'status' => TicketStatus::Open,
            'billing_mode' => BillingMode::SupportHours,
        ];
    }

    /**
     * Indicate that the ticket has been closed off.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now()->subDays(3),
        ]);
    }

    /**
     * Indicate that the ticket is blocked on the client.
     */
    public function waitingOnClient(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::WaitingOnClient,
        ]);
    }
}
