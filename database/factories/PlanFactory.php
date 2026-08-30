<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'blurb' => fake()->sentence(4),
            'price' => fake()->numberBetween(50, 600),
            'hours_per_month' => fake()->randomElement([0, 6, 20]),
            'response_time' => fake()->randomElement(['next working day', 'within 1 working day', 'same working day']),
            'features' => fake()->sentences(4),
            'is_live' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the plan is highlighted as the one most clients choose.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Indicate that the plan is no longer offered to clients.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_live' => false,
        ]);
    }
}
