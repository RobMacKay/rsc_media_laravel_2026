<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => Ticket::class,
            'attachable_id' => Ticket::factory(),
            'name' => fake()->slug(3).'.pdf',
            'kind' => 'PDF',
            'size' => fake()->numberBetween(2_000, 900_000),
            'shared_with_client' => true,
        ];
    }

    /**
     * Indicate that the file is for the studio's eyes only.
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'shared_with_client' => false,
        ]);
    }
}
