<?php

namespace Database\Factories;

use App\Models\Division;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Division>
 */
class DivisionFactory extends Factory
{
    protected $model = Division::class;

    public function definition(): array
    {
        return [
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'name' => $this->faker->unique()->jobTitle(),
        ];
    }
}
