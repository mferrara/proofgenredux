<?php

namespace Database\Factories;

use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShowClass>
 */
class ShowClassFactory extends Factory
{
    protected $model = ShowClass::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->regexify('[A-Z0-9]{8}_[A-Z0-9]{8}'),
            'show_id' => Show::factory(),
            'name' => $this->faker->word(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
