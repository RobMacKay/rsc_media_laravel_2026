<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $host = fake()->unique()->domainName();

        return [
            'team_id' => Team::factory(),
            'name' => $host,
            'url' => 'https://'.$host,
            'host' => $host,
            'is_active' => true,
            'status' => SiteStatus::Unknown,
        ];
    }

    /**
     * Indicate that the site is one of the studio's own, not a client's.
     */
    public function studioOwned(): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => null,
        ]);
    }

    /**
     * Indicate that the site was last seen answering.
     */
    public function up(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Up,
            'http_status' => 200,
            'response_ms' => fake()->numberBetween(90, 800),
            'ssl_valid' => true,
            'ssl_expires_at' => now()->addMonths(2),
            'ssl_issuer' => "Let's Encrypt",
            'last_checked_at' => now(),
            'last_up_at' => now(),
        ]);
    }

    /**
     * Indicate that the site was last seen failing.
     */
    public function down(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Down,
            'http_status' => 503,
            'last_error' => 'The site answered with 503.',
            'last_checked_at' => now(),
            'last_down_at' => now(),
            'consecutive_failures' => 3,
        ]);
    }
}
