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
     * Indicate that the invoice has been settled in part.
     */
    public function partPaid(float $paid): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Partial,
            'paid_to_date' => $paid,
        ]);
    }

    /**
     * Indicate that this is a record of work somebody else billed, rather than
     * an invoice the studio raised and is waiting on.
     */
    public function recordOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => 'REC-'.fake()->unique()->numerify('0###'),
            'record_only' => true,
            'status' => InvoiceStatus::Paid,
            'paid_at' => now()->subDays(2),
            'vat_rate' => 0,
            'external_reference' => 'QSL'.fake()->numerify('##########'),
        ]);
    }

    /**
     * Indicate that the invoice came over from the studio's previous system.
     */
    public function imported(): static
    {
        return $this->state(function (array $attributes) {
            $number = fake()->unique()->numerify('0###');

            return [
                'number' => 'IN-'.$number,
                'external_reference' => $number,
                'vat_rate' => 0,
            ];
        });
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
