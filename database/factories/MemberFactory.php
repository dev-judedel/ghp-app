<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('T###')),
            'email' => $this->faker->unique()->safeEmail(),
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'last_name' => $this->faker->lastName(),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->lastName(),
            'address' => $this->faker->address(),
            'birthdate' => $this->faker->dateTimeBetween('-60 years', '-22 years'),
            'civil_status' => $this->faker->randomElement([Member::CIVIL_STATUS_SINGLE, Member::CIVIL_STATUS_MARRIED]),
            'apply_date' => $this->faker->dateTimeBetween('-10 years', 'now'),
            'deduction_start_date' => $this->faker->dateTimeBetween('-10 years', 'now'),
            'ghp_amount' => 3600,
            'remarks' => null,
        ];
    }
}
