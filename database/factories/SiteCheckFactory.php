<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteCheck>
 */
class SiteCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'checked_at' => now(),
            'status' => SiteStatus::Up,
            'http_status' => 200,
            'response_ms' => fake()->numberBetween(90, 800),
            'ssl_valid' => true,
            'ssl_expires_at' => now()->addMonths(2),
        ];
    }

    /**
     * Indicate that the check found the site failing.
     */
    public function down(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Down,
            'http_status' => 503,
            'error' => 'The site answered with 503.',
        ]);
    }
}
