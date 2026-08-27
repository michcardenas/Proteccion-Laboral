<?php

namespace Tests\Unit\Console;

use App\Console\Commands\DriveMapClients;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cómo se llama un cliente creado desde su carpeta de Drive.
 *
 * El despacho numera las carpetas para ordenarlas en Drive («2 BOLUGA»). Ese
 * número es de la carpeta, no del cliente, y arrastrarlo a la razón social
 * dejaría treinta y dos fichas con un número delante.
 *
 * Lo que NO hace es adivinar: el resto del nombre se respeta tal cual, aunque
 * venga abreviado o en mayúsculas, porque son los nombres con los que el
 * despacho reconoce a sus clientes.
 */
class NombreDeClienteDesdeDriveTest extends TestCase
{
    private function nombre(string $carpeta): string
    {
        $metodo = new ReflectionMethod(DriveMapClients::class, 'nombreDeCliente');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(DriveMapClients::class), $carpeta);
    }

    public function test_quita_la_numeracion_de_la_carpeta(): void
    {
        $this->assertSame('BOLUGA', $this->nombre('2 BOLUGA'));
        $this->assertSame('ZONA MEDICA', $this->nombre('1 ZONA MEDICA'));
        $this->assertSame('COMPRAVENTA GARCES', $this->nombre('16 COMPRAVENTA GARCES'));
        $this->assertSame('TORREON c', $this->nombre('4 TORREON c'));
    }

    /** Con separador y con espacios de más también. */
    public function test_tolera_separadores_y_espacios(): void
    {
        $this->assertSame('VISOFT', $this->nombre('3. VISOFT'));
        $this->assertSame('VISOFT', $this->nombre('3 - VISOFT'));
        $this->assertSame('VISOFT', $this->nombre('  7   VISOFT  '));
    }

    /** Sin numeración, el nombre se deja intacto. */
    public function test_respeta_el_nombre_cuando_no_hay_numeracion(): void
    {
        $this->assertSame('SUMITOR', $this->nombre('SUMITOR'));
        $this->assertSame('VISUAL PUBLICIDAD', $this->nombre('VISUAL PUBLICIDAD'));
    }

    /**
     * Un número pegado al nombre es parte del nombre, no numeración: hace falta
     * el espacio para separarlos. «3M» es una empresa, no la carpeta 3.
     */
    public function test_un_numero_pegado_al_nombre_no_se_toca(): void
    {
        $this->assertSame('3M COLOMBIA', $this->nombre('3M COLOMBIA'));
    }

    /** Y una carpeta que es solo un número no puede quedar sin nombre. */
    public function test_una_carpeta_solo_numerica_conserva_el_numero(): void
    {
        $this->assertSame('12', $this->nombre('12'));
    }
}
