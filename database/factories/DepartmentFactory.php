<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'division_id' => null,
        ];
    }

    public function forDivision(?Division $division = null): static
    {
        return $this->state(fn () => [
            'division_id' => ($division ?? Division::factory()->create())->id,
        ]);
    }
}
