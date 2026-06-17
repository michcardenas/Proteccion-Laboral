<?php

namespace Database\Seeders;

use App\Models\ServiceChecklistItem;
use App\Models\ServiceStageTemplate;
use App\Models\ServiceTaskTemplate;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceTypeSeeder extends Seeder
{
    public const CATALOG = [
        [
            // Plan de trabajo real: "Hoja de Ruta: Diagnóstico e Implementación".
            // Las fechas de entrega del documento (10, 17 y 24 de junio sobre un
            // plan abierto el 1 de junio) se modelan como SLA relativos a la apertura.
            'nombre' => 'Diagnóstico e Implementación Laboral',
            'modalidad' => 'diagnostico_implementacion',
            'descripcion' => 'Proceso personalizado de diagnóstico e implementación del plan de mejora del área de talento humano: se intervienen las etapas de la relación contractual (selección, vinculación, ejecución y desvinculación) conforme a los hallazgos del diagnóstico, entregando formatos de contratación actualizados, manuales de funciones y políticas laborales.',
            'stages' => [
                [
                    'nombre' => 'Etapa 1 · Diagnóstico y actualización contractual',
                    'descripcion' => 'Actualización de modelos contractuales, revisión de cláusulas sensibles y diagnóstico de las carpetas de los trabajadores.',
                    'sla_dias' => 9,
                    'rol_default' => 'abogado_interno',
                    'checklist' => [
                        'Actualizar modelos de contratos laborales (término fijo, indefinido y obra o labor)',
                        'Actualizar contratos de prestación de servicios',
                        'Revisar cláusulas sensibles: confidencialidad, manejo de información y propiedad intelectual',
                        'Revisar y diagnosticar carpetas de los trabajadores',
                        'Entregar formatos: otrosíes laborales, acuerdos de confidencialidad, autorizaciones de tratamiento de datos y cartas de terminación laboral',
                        'Entregar minutas del área de talento humano',
                    ],
                ],
                [
                    'nombre' => 'Etapa 2 · Manuales de funciones por cargo',
                    'descripcion' => 'Elaboración de los manuales de funciones por cada cargo conforme al objeto social y las dinámicas laborales y operacionales.',
                    'sla_dias' => 16,
                    'rol_default' => 'abogado_interno',
                    'checklist' => [
                        'Elaborar manuales de funciones por cargo conforme al objeto social de la empresa',
                    ],
                ],
                [
                    'nombre' => 'Etapa 3 · Implementación de políticas laborales',
                    'descripcion' => 'Creación o actualización de políticas internas y ajustes a la jornada laboral.',
                    'sla_dias' => 23,
                    'rol_default' => 'abogado_interno',
                    'checklist' => [
                        'Crear o actualizar la política de tratamiento de datos personales',
                        'Ajustar jornada laboral, horarios y control de horas extras',
                        'Entregar el manual de políticas laborales',
                        'Entregar política de uso de herramientas tecnológicas y correo corporativo',
                        'Entregar protocolo de atención de quejas laborales',
                        'Entregar política de protección de información empresarial',
                    ],
                ],
            ],
            // Entregables transversales del acompañamiento (durante todo el proceso).
            'transversales' => [
                'Atención permanente de consultas jurídico-laborales',
                'Respuesta y acompañamiento frente a derechos de petición, tutelas y requerimientos',
                'Acompañamiento en procesos disciplinarios',
                'Conceptos jurídicos laborales especializados',
                'Revisión preventiva de decisiones de desvinculación',
                'Acompañamiento en eventuales inspecciones del Ministerio del Trabajo',
                'Asesoría preventiva en seguridad social y UGPP',
            ],
            // Rúbrica del tablero (derivada del alcance del contrato de prestación de servicios).
            'tasks' => [
                ['titulo' => 'Elaborar y socializar el reglamento interno de trabajo (RIT)', 'prioridad' => 'alta', 'sla_dias' => 14,
                    'descripcion' => 'Elaborar el RIT conforme a las normas aplicables, adecuado a las necesidades de la empresa, y socializarlo.'],
                ['titulo' => 'Revisar expedientes laborales y generar otrosíes', 'prioridad' => 'alta', 'sla_dias' => 10,
                    'descripcion' => 'Revisar las carpetas/expedientes del personal vinculado y generar los otrosíes a que haya lugar.'],
                ['titulo' => 'Acompañar trámite de proceso disciplinario', 'prioridad' => 'media', 'sla_dias' => 12,
                    'descripcion' => 'Acompañamiento y trámite del proceso disciplinario con el trabajador correspondiente.'],
                ['titulo' => 'Entregar y socializar manuales de funciones', 'prioridad' => 'media', 'sla_dias' => 18],
                ['titulo' => 'Reunión de cierre y entrega de entregables', 'prioridad' => 'alta', 'sla_dias' => 23,
                    'descripcion' => 'Agendar la reunión de cierre donde se entregan los entregables respectivos.'],
            ],
        ],
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
            'tasks' => [
                ['titulo' => 'Recolectar documentación corporativa del cliente', 'prioridad' => 'media', 'sla_dias' => 5],
                ['titulo' => 'Emitir informe de hallazgos del diagnóstico', 'prioridad' => 'alta', 'sla_dias' => 14],
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
            'tasks' => [
                ['titulo' => 'Recoger relato y documentos del cliente', 'prioridad' => 'alta', 'sla_dias' => 2],
                ['titulo' => 'Definir vía de actuación y estrategia', 'prioridad' => 'media', 'sla_dias' => 5],
                ['titulo' => 'Entregar informe final al cliente', 'prioridad' => 'media', 'sla_dias' => 18],
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
            'tasks' => [
                ['titulo' => 'Estudiar pruebas y definir estrategia procesal', 'prioridad' => 'alta', 'sla_dias' => 10],
                ['titulo' => 'Radicar pieza procesal ante el juzgado', 'prioridad' => 'urgente', 'sla_dias' => 20],
                ['titulo' => 'Notificar al cliente el resultado final', 'prioridad' => 'media', 'sla_dias' => null],
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

            // Idempotencia: limpiamos plantillas previas de este servicio.
            // Borrar las stage templates arrastra (cascade) sus checklist items
            // de etapa, pero NO los de nivel servicio (transversales) ni las
            // plantillas de tareas, que removemos explícitamente.
            $serviceType->stageTemplates()->delete();
            ServiceChecklistItem::where('service_type_id', $serviceType->id)
                ->whereNull('service_stage_template_id')
                ->delete();
            ServiceTaskTemplate::where('service_type_id', $serviceType->id)->delete();

            foreach ($service['stages'] as $orden => $stage) {
                $template = ServiceStageTemplate::create([
                    'service_type_id' => $serviceType->id,
                    'orden' => $orden + 1,
                    'nombre' => $stage['nombre'],
                    'descripcion' => $stage['descripcion'] ?? null,
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

            // Entregables transversales del acompañamiento (checklist a nivel
            // servicio: ProcessService los engancha a la primera etapa del proceso).
            foreach (($service['transversales'] ?? []) as $itemOrden => $descripcion) {
                ServiceChecklistItem::create([
                    'service_type_id' => $serviceType->id,
                    'service_stage_template_id' => null,
                    'descripcion' => $descripcion,
                    'es_obligatorio' => false,
                    'orden' => $itemOrden + 1,
                ]);
            }

            // Rúbrica del tablero Kanban: tarjetas que se autogeneran al crear el proceso.
            foreach (($service['tasks'] ?? []) as $taskOrden => $task) {
                ServiceTaskTemplate::create([
                    'service_type_id' => $serviceType->id,
                    'orden' => $taskOrden + 1,
                    'titulo' => $task['titulo'],
                    'descripcion' => $task['descripcion'] ?? null,
                    'prioridad' => $task['prioridad'] ?? 'media',
                    'sla_dias' => $task['sla_dias'] ?? null,
                    'es_activo' => true,
                ]);
            }
        }
    }
}
