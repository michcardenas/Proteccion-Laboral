<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

/**
 * Clientes reales identificados a partir del tráfico del buzón
 * automatizacion@proteccionlaboral.co (junio 2026).
 *
 * OJO: razones sociales y NITs marcados "validar" se infirieron de firmas de
 * correo y dominios; deben confirmarse con Dirección antes de producción.
 * Idempotente: updateOrCreate por razon_social.
 */
class RealClientsSeeder extends Seeder
{
    public function run(): void
    {
        $hoy = now()->toDateString();

        $activos = [
            [
                'razon_social' => 'ZONAMEDICA MR SAS',
                'nit' => null,
                'ciudad' => 'Bogotá',
                'sector' => 'Salud',
                'contacto_principal' => 'Andrés Medina Rodríguez (Gerente General)',
                'email' => 'gerencia@zonamedicaips.com',
                'telefono' => '3187170419',
                'notas' => 'Dominios: zonamedicaips.com (gestionhumana@, tramiteslegales@, recepcion@, gerencia@). Asesoría permanente + casos judiciales (tutelas INPEC/FAC). NIT por validar.',
            ],
            [
                'razon_social' => 'BOLUGA SAS',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Servicios',
                'contacto_principal' => 'Ana B. (RRHH)',
                'email' => 'rrhhab@boluga.com.co',
                'telefono' => null,
                'notas' => 'Dominio: boluga.com.co (rrhhab@, tesoreria@, ohuertas@). Depuración pensional Colpensiones, conceptos jurídicos. Razón social y NIT por validar.',
            ],
            [
                'razon_social' => 'SUPRAESPECIALIDADES OFTALMOLOGICAS DEL TOLIMA SAS',
                'nit' => '900445497',
                'ciudad' => 'Ibagué',
                'sector' => 'Salud',
                'contacto_principal' => 'Yulieth Lorena Leal Andrade (Coord. administrativa y gestión humana)',
                'email' => 'thsupra1@gmail.com',
                'telefono' => null,
                'notas' => 'Correos: thsupra1@gmail.com, gerenciasupra10@gmail.com, contabilidadsupraespecialidade@gmail.com. NIT tomado de clave de certificado ARL en correo; validar.',
            ],
            [
                'razon_social' => 'COLCHONES Y MUEBLES RESPLANDOR SAS',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Manufactura',
                'contacto_principal' => null,
                'email' => 'colchonesresplandor2021@gmail.com',
                'telefono' => null,
                'notas' => 'Contrato de prestación de servicios con visita semanal. NIT por validar.',
            ],
            [
                'razon_social' => 'AGROPECUARIA LA CEIBA SAS',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Agropecuario',
                'contacto_principal' => 'Luz Ángela Gonella',
                'email' => 'agrolaceiba@yahoo.com',
                'telefono' => null,
                'notas' => 'Correos: agrolaceiba@yahoo.com, angelagonella@yahoo.com, coordinacionsstceiba@gmail.com. Prediagnóstico/documentación (RIT, profesiograma, políticas). NIT por validar.',
            ],
            [
                'razon_social' => 'DEPOSITO PRIMAVERA',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Comercio',
                'contacto_principal' => null,
                'email' => 'talentohumanodtp@gmail.com',
                'telefono' => null,
                'notas' => 'Visitas de acompañamiento, manuales de funciones, organigrama, derecho de petición NUEVA EPS. Razón social y NIT por validar.',
            ],
            [
                'razon_social' => 'GPT SEGUROS',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Seguros',
                'contacto_principal' => null,
                'email' => 'gerencia@gptseguros.com',
                'telefono' => null,
                'notas' => 'Correos: gerencia@gptseguros.com, coordinacion@ibaincrear.com.co. Plan de trabajo mensual, minuta contrato freelance seguros. Razón social y NIT por validar.',
            ],
            [
                'razon_social' => 'MELENDEZ Y MELENDEZ LTDA',
                'nit' => '890702700',
                'dv' => '1',
                'ciudad' => null,
                'sector' => null,
                'contacto_principal' => null,
                'email' => 'melendezlimitada@gmail.com',
                'telefono' => '3171177999',
                'notas' => 'NIT y teléfono tomados de la firma de su correo.',
            ],
            [
                'razon_social' => 'ELIAS ACOSTA',
                'nit' => null,
                'ciudad' => null,
                'sector' => null,
                'contacto_principal' => 'Lina (Talento Humano)',
                'email' => 'talentohumano@eliasacosta.com',
                'telefono' => null,
                'notas' => 'Dominio: eliasacosta.com (TalentoHumano@, SST@, compras@). Caso Cocola/inconformidad laboral. Razón social completa y NIT por validar.',
            ],
            [
                'razon_social' => 'LINEA DE VIDA',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Salud',
                'contacto_principal' => 'Carolina Martínez (Contadora)',
                'email' => 'contador.ldv@lineadevida.com.co',
                'telefono' => null,
                'notas' => 'Dominio: lineadevida.com.co. Caso reintegro laboral. Razón social completa y NIT por validar.',
            ],
            [
                'razon_social' => 'GRUPO CUIDAR',
                'nit' => null,
                'ciudad' => null,
                'sector' => 'Salud',
                'contacto_principal' => null,
                'email' => 'grupocuidarcontratacion@gmail.com',
                'telefono' => null,
                'notas' => 'Manuales operativos de contratistas. Razón social completa y NIT por validar.',
            ],
        ];

        // Empresas vistas solo en visitas preventivas / gestión comercial: aún no son clientes.
        $prospectos = [
            ['razon_social' => 'CASA BEST SAS', 'notas' => 'Visita preventiva jurídico laboral programada (gestioncomercial@).'],
            ['razon_social' => 'LABORATORIOS HERIGAR', 'email' => 'tesoreria@laboratoriosherigar.com', 'notas' => 'Visita preventiva jurídico laboral programada.'],
            ['razon_social' => 'MULTIGAS DE COLOMBIA', 'email' => 'multigasdecolombia@outlook.es', 'notas' => 'Visita preventiva jurídica laboral programada.'],
            ['razon_social' => 'INNOVA SALUD ORAL', 'email' => 'bienestarinnovar@gmail.com', 'sector' => 'Salud', 'notas' => 'Visita preventiva reprogramada.'],
            ['razon_social' => 'FERRETERIA LA ESPAÑOLA', 'email' => 'cataflorez967@gmail.com', 'sector' => 'Comercio', 'notas' => 'Reunión por Reglamento Interno de Trabajo.'],
        ];

        foreach ($activos as $data) {
            Client::updateOrCreate(
                ['razon_social' => $data['razon_social']],
                $data + ['estado' => 'activo', 'fecha_alta' => $hoy]
            );
        }

        foreach ($prospectos as $data) {
            Client::updateOrCreate(
                ['razon_social' => $data['razon_social']],
                $data + ['estado' => 'prospecto', 'fecha_alta' => $hoy]
            );
        }
    }
}
