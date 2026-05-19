<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Process;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Process>
 */
class ProcessFactory extends Factory
{
    protected $model = Process::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'service_type_id' => ServiceType::query()->inRandomOrder()->value('id') ?? ServiceType::factory(),
            'codigo' => 'PRC-'.fake()->unique()->numerify('######'),
            'titulo' => fake()->sentence(6),
            'descripcion' => fake()->paragraph(),
            'estado' => 'abierto',
            'fecha_apertura' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
