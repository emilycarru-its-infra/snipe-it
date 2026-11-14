<?php

namespace Database\Factories;

use App\Models\ProductIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductIdentityFactory extends Factory
{
    protected $model = ProductIdentity::class;

    public function definition(): array
    {
        return [
            'name'      => $this->faker->unique()->words(2, true).' Studio',
            'publisher' => $this->faker->company(),
        ];
    }

    /** Add alias rows as [platform, match_type, pattern] triples. */
    public function withAliases(array $aliases): static
    {
        return $this->afterCreating(function (ProductIdentity $identity) use ($aliases) {
            foreach ($aliases as [$platform, $matchType, $pattern]) {
                $identity->aliases()->create([
                    'platform'   => $platform,
                    'match_type' => $matchType,
                    'pattern'    => $pattern,
                ]);
            }
        });
    }
}
