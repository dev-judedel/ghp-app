<?php

namespace Database\Factories;

use App\Models\Dependent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dependent>
 */
class DependentFactory extends Factory
{
    protected $model = Dependent::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->firstName(),
            'relation' => $this->faker->randomElement(['Spouse', 'Son', 'Daughter']),
            'birthdate' => $this->faker->dateTimeBetween('-40 years', 'now'),
        ];
    }
}
