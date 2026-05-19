<?php

namespace Database\Seeders;

use App\Models\ServiceChecklistItem;
use App\Models\ServiceStageTemplate;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceTypeSeeder extends Seeder
{
    public const CATALOG = [
        [
            'nombre' => 'Asesoría Laboral Permanente',
            'modalidad' => 'permanente',
            'descripcion' => 'Acompañamiento jurídico continuo en materia laboral para empresas: consultas, conceptos, política interna y prevención.',
            'stages' => [
                ['nombre' => 'Onboarding del cliente', 'sla_dias' => 7, 'rol_default' => 'coordinador', 'checklist' => [
                    'Recolectar documentación corporativa básica',
                    'Verificar reglamento interno de trabajo',
                    'Revisar contratos vigentes',
                ]],
                ['nombre' => 'Diagnóstico inicial', 'sla_dias' => 14, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Mapear riesgos laborales',
                    'Identificar gaps frente a la normativa',
                    'Emitir informe de hallazgos',
                ]],
                ['nombre' => 'Plan de seguimiento mensual', 'sla_dias' => null, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Definir cadencia de reuniones',
                    'Acordar canal de consultas',
                ]],
            ],
        ],
        [
            'nombre' => 'Servicio por Evento',
            'modalidad' => 'por_evento',
            'descripcion' => 'Atención puntual ante un hecho específico: terminación de contrato, sanción disciplinaria, conciliación, etc.',
            'stages' => [
                ['nombre' => 'Recepción y validación del caso', 'sla_dias' => 2, 'rol_default' => 'coordinador', 'checklist' => [
                    'Recoger relato y documentos del cliente',
                    'Confirmar alcance del servicio',
                ]],
                ['nombre' => 'Análisis y estrategia', 'sla_dias' => 5, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Revisar marco normativo aplicable',
                    'Definir vía de actuación',
                ]],
                ['nombre' => 'Ejecución', 'sla_dias' => 15, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Generar documentos requeridos',
                    'Notificar a las partes',
                ]],
                ['nombre' => 'Cierre y entrega', 'sla_dias' => 3, 'rol_default' => 'coordinador', 'checklist' => [
                    'Entregar informe final al cliente',
                    'Archivar documentación',
                ]],
            ],
        ],
        [
            'nombre' => 'Representación Judicial Laboral',
            'modalidad' => 'judicial',
            'descripcion' => 'Defensa o demanda en procesos laborales ante jueces ordinarios y altas cortes.',
            'stages' => [
                ['nombre' => 'Estudio del caso', 'sla_dias' => 10, 'rol_default' => 'apoderado', 'checklist' => [
                    'Estudiar pruebas y antecedentes',
                    'Definir estrategia procesal',
                ]],
                ['nombre' => 'Presentación o contestación', 'sla_dias' => 20, 'rol_default' => 'apoderado', 'checklist' => [
                    'Redactar pieza procesal',
                    'Validar con coordinador',
                    'Radicar ante el juzgado',
                ]],
                ['nombre' => 'Audiencias', 'sla_dias' => null, 'rol_default' => 'apoderado', 'checklist' => [
                    'Preparar testigos',
                    'Asistir audiencia inicial',
                    'Asistir audiencia de pruebas',
                ]],
                ['nombre' => 'Sentencia y recursos', 'sla_dias' => null, 'rol_default' => 'apoderado', 'checklist' => [
                    'Analizar sentencia',
                    'Decidir interposición de recursos',
                ]],
                ['nombre' => 'Cierre', 'sla_dias' => 5, 'rol_default' => 'coordinador', 'checklist' => [
                    'Notificar al cliente resultado final',
                    'Archivar expediente',
                ]],
            ],
        ],
        [
            'nombre' => 'Estructuración Estratégica',
            'modalidad' => 'estrategico',
            'descripcion' => 'Diseño de estructuras laborales para empresas: compensación, beneficios, gobierno corporativo y políticas.',
            'stages' => [
                ['nombre' => 'Discovery', 'sla_dias' => 10, 'rol_default' => 'coordinador', 'checklist' => [
                    'Entrevistar líderes clave',
                    'Levantar mapa de procesos',
                ]],
                ['nombre' => 'Diseño', 'sla_dias' => 20, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Proponer estructura',
                    'Validar con dirección',
                ]],
                ['nombre' => 'Implementación', 'sla_dias' => 30, 'rol_default' => 'coordinador', 'checklist' => [
                    'Capacitar al equipo del cliente',
                    'Entregar plantillas y políticas',
                ]],
            ],
        ],
        [
            'nombre' => 'Capacitación al Personal',
            'modalidad' => 'capacitacion',
            'descripcion' => 'Sesiones formativas a equipos de talento humano y líderes en derecho laboral aplicado.',
            'stages' => [
                ['nombre' => 'Definición de temario', 'sla_dias' => 5, 'rol_default' => 'coordinador', 'checklist' => [
                    'Acordar objetivos con el cliente',
                    'Diseñar agenda',
                ]],
                ['nombre' => 'Preparación de materiales', 'sla_dias' => 7, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Generar presentación',
                    'Preparar casos prácticos',
                ]],
                ['nombre' => 'Ejecución de la capacitación', 'sla_dias' => null, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Realizar sesión',
                    'Entregar certificados',
                ]],
                ['nombre' => 'Seguimiento', 'sla_dias' => 15, 'rol_default' => 'coordinador', 'checklist' => [
                    'Recoger feedback',
                    'Enviar grabación y materiales',
                ]],
            ],
        ],
        [
            'nombre' => 'Prediagnóstico Empresarial',
            'modalidad' => 'prediagnostico',
            'descripcion' => 'Revisión inicial sin compromiso de los frentes laborales más críticos del cliente.',
            'stages' => [
                ['nombre' => 'Reunión exploratoria', 'sla_dias' => 3, 'rol_default' => 'coordinador', 'checklist' => [
                    'Agendar reunión',
                    'Levantar minuta',
                ]],
                ['nombre' => 'Revisión documental', 'sla_dias' => 5, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Revisar contratos tipo',
                    'Revisar reglamento interno',
                    'Revisar afiliaciones SST',
                ]],
                ['nombre' => 'Informe de prediagnóstico', 'sla_dias' => 5, 'rol_default' => 'abogado_interno', 'checklist' => [
                    'Redactar informe ejecutivo',
                    'Presentar al cliente',
                ]],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $service) {
            $serviceType = ServiceType::updateOrCreate(
                ['slug' => Str::slug($service['nombre'])],
                [
                    'nombre' => $service['nombre'],
                    'descripcion' => $service['descripcion'],
                    'modalidad' => $service['modalidad'],
                    'es_activo' => true,
                ]
            );

            $serviceType->stageTemplates()->delete();

            foreach ($service['stages'] as $orden => $stage) {
                $template = ServiceStageTemplate::create([
                    'service_type_id' => $serviceType->id,
                    'orden' => $orden + 1,
                    'nombre' => $stage['nombre'],
                    'rol_responsable_default' => $stage['rol_default'] ?? null,
                    'sla_dias' => $stage['sla_dias'] ?? null,
                ]);

                foreach (($stage['checklist'] ?? []) as $itemOrden => $descripcion) {
                    ServiceChecklistItem::create([
                        'service_stage_template_id' => $template->id,
                        'descripcion' => $descripcion,
                        'es_obligatorio' => true,
                        'orden' => $itemOrden + 1,
                    ]);
                }
            }
        }
    }
}
