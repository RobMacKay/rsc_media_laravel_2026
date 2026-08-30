<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issued = fake()->dateTimeBetween('-3 months', 'now');

        return [
            'number' => 'RSC-'.fake()->unique()->numerify('0###'),
            'team_id' => Team::factory(),
            'type' => fake()->randomElement(InvoiceType::cases()),
            'note' => fake()->sentence(4),
            'amount' => fake()->numberBetween(75, 5000),
            'vat_rate' => 20,
            'issued_on' => $issued,
            'due_on' => (clone $issued)->modify('+21 days'),
            'status' => InvoiceStatus::Sent,
        ];
    }

    /**
     * Indicate that the invoice has been settled.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => now()->subDays(2),
        ]);
    }

    /**
     * Indicate that the invoice is past its due date.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Overdue,
            'due_on' => now()->subWeek(),
        ]);
    }
}
