<?php

namespace Database\Factories;

use App\Models\LeaseDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaseDecisionFactory extends Factory
{
    protected $model = LeaseDecision::class;

    public function definition()
    {
        return [
            'contract_reference' => strtoupper($this->faker->bothify('ECI########')),
            'decision_type' => $this->faker->randomElement(LeaseDecision::DECISION_TYPES),
            'decision_date' => $this->faker->date(),
            'amount' => $this->faker->randomFloat(2, 1000, 100000),
            'status' => 'pending',
        ];
    }
}
