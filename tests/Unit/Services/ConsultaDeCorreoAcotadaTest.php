<?php

namespace Tests\Unit\Services;

use App\Services\GmailService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * La búsqueda de correo sin leer, acotada por fecha.
 *
 * Sin acotar, conectar una cuenta ingiere y clasifica TODO su histórico de una
 * sentada. Le pasó a la cuenta de automatización: tres meses de correo
 * procesados de golpe en cuanto se desatascó el sondeo, con su factura. Con
 * cuatro abogadas por conectar, eso serían cuatro sustos más.
 */
class ConsultaDeCorreoAcotadaTest extends TestCase
{
    private function consulta(): string
    {
        $m = new ReflectionMethod(GmailService::class, 'consultaDeNoLeidos');
        $m->setAccessible(true);

        return $m->invoke(new GmailService);
    }

    public function test_sin_fecha_configurada_no_acota(): void
    {
        config()->set('gmail.ingest_since', null);

        $this->assertSame('is:unread', $this->consulta());
    }

    public function test_con_fecha_acota_en_el_formato_de_gmail(): void
    {
        config()->set('gmail.ingest_since', '2026-08-01');

        $this->assertSame('is:unread after:2026/08/01', $this->consulta());
    }

    /**
     * Una fecha mal escrita no puede dejar la ingesta sin funcionar: se ignora
     * y se sondea como siempre. Perder correo es peor que ingerir de más.
     */
    public function test_una_fecha_invalida_se_ignora(): void
    {
        config()->set('gmail.ingest_since', 'el mes pasado');

        $this->assertSame('is:unread', $this->consulta());
    }

    public function test_los_espacios_sobrantes_no_estorban(): void
    {
        config()->set('gmail.ingest_since', '  2026-08-27  ');

        $this->assertSame('is:unread after:2026/08/27', $this->consulta());
    }
}
