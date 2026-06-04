<?php

namespace Database\Factories;

use App\Models\Round;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Round>
 */
class RoundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $guess = fake()->numberBetween(50, 8000);
        $actual = fake()->numberBetween(50, 8000);

        return [
            'player_name' => fake()->firstName(),
            'target_name' => fake()->randomElement(['App One', 'App Two', 'App Three']),
            'target_url' => fake()->url(),
            'guess_ms' => $guess,
            'actual_ms' => $actual,
            'latency_ms' => fake()->numberBetween(20, 250),
            'delta_ms' => abs($guess - $actual),
        ];
    }
}
