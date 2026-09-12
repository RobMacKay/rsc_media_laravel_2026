<?php

namespace Database\Factories;

use App\Enums\InvoiceType;
use App\Models\RecurringInvoice;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
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
            'type' => InvoiceType::AdHoc,
            'note' => 'Hosting and maintenance',
            'amount' => 20,
            'day_of_month' => 1,
            'starts_on' => now()->subYear(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the schedule bills on the given day of the month.
     */
    public function onDay(int $day): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_month' => $day,
        ]);
    }

    /**
     * Indicate that the schedule raises a paid record rather than an invoice
     * to send, because somebody else bills the work.
     */
    public function recordOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'record_only' => true,
        ]);
    }

    /**
     * Indicate that the schedule has been switched off.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the arrangement has come to an end.
     */
    public function endedOn(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'ends_on' => $date,
        ]);
    }
}
