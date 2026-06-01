<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'name'            => $this->faker->company().' Contract',
            'contract_number' => strtoupper($this->faker->bothify('CT-####-???')),
            'fiscal_year'     => 'FY2026-27',
            'is_active'       => true,
            'workflow_status' => 'active',
            'source'          => 'manual',
            'is_synthesized'  => false,
            'created_by'      => User::factory()->superuser(),
        ];
    }

    public function unattributed(): self
    {
        return $this->state(fn () => [
            'name'            => 'Unattributed',
            'contract_number' => 'UNATTRIBUTED',
            'is_synthesized'  => true,
        ]);
    }
}
