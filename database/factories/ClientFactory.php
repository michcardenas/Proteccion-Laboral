<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'razon_social' => fake()->company().' S.A.S.',
            'nit' => fake()->unique()->numerify('#########'),
            'dv' => (string) fake()->numberBetween(0, 9),
            'ciudad' => fake()->randomElement(['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Bucaramanga']),
            'sector' => fake()->randomElement(['Manufactura', 'Servicios', 'Tecnología', 'Salud', 'Educación', 'Construcción']),
            'contacto_principal' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'telefono' => fake()->numerify('60# ### ####'),
            'fecha_alta' => fake()->dateTimeBetween('-2 years', 'now'),
            'estado' => 'activo',
            'notas' => null,
        ];
    }
}
