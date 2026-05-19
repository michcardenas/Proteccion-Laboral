<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'client_id' => Client::factory(),
            'service_type_id' => ServiceType::query()->inRandomOrder()->value('id') ?? ServiceType::factory(),
            'codigo' => 'CTR-'.fake()->unique()->numerify('######'),
            'fecha_inicio' => $start,
            'fecha_fin' => fake()->boolean(60) ? (clone $start)->modify('+1 year') : null,
            'valor' => fake()->randomFloat(2, 1_000_000, 50_000_000),
            'modalidad_pago' => fake()->randomElement(['mensual', 'unico', 'por_etapa']),
            'estado' => 'activo',
        ];
    }
}
