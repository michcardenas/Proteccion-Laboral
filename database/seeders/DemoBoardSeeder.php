<?php

namespace Database\Seeders;

use App\Models\EmailIngestion;
use App\Models\Process;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Datos de prueba para el Tablero Kanban de tareas.
 * Crea (o reutiliza) 3 procesos y ~15 tareas repartidas en todos los estados.
 * Idempotente: se puede correr varias veces sin duplicar.
 */
class DemoBoardSeeder extends Seeder
{
    public function run(): void
    {
        $userIds = User::query()->pluck('id')->all();
        if (empty($userIds)) {
            $this->command->warn('No hay usuarios; corre primero InitialUsersSeeder.');

            return;
        }
        $creador = $userIds[0];
        $pick = fn (int $i) => $userIds[$i % count($userIds)];

        // --- Procesos demo (reutiliza el primero existente, crea 2 más) ---
        $base = Process::query()->orderBy('id')->first();
        $clientId = $base?->client_id ?? \App\Models\Client::query()->value('id');
        $serviceId = $base?->service_type_id ?? \App\Models\ServiceType::query()->value('id');

        $p1 = $base ?? $this->makeProcess('PL-DEMO-001', 'Proceso ordinario laboral — Demo', $clientId, $serviceId, 'en_curso');
        $p2 = $this->makeProcess('PL-DEMO-002', 'Derecho de petición — Innova Salud Oral', $clientId, $serviceId, 'en_curso');
        $p3 = $this->makeProcess('PL-DEMO-003', 'Visita preventiva — Multigas de Colombia', $clientId, $serviceId, 'abierto');

        // --- Resumen IA para P2 (correo de origen clasificado) ---
        EmailIngestion::query()->firstOrCreate(
            ['message_id' => 'demo-board-p2'],
            [
                'from' => 'contacto@innovasaludoral.com',
                'to' => 'automatizacion@proteccionlaboral.com',
                'subject' => 'Derecho de petición — respuesta requerida',
                'received_at' => Carbon::now()->subDays(2),
                'raw_payload' => ['demo' => true],
                'body_text' => 'Solicitamos respuesta al derecho de petición radicado la semana pasada.',
                'status' => EmailIngestion::STATUS_PROCESSED,
                'process_id' => $p2->id,
                'ai_classification' => [
                    'action' => 'seguimiento_proceso',
                    'confidence' => 0.82,
                    'summary' => 'El cliente Innova Salud Oral solicita respuesta formal a un derecho de petición radicado; requiere elaborar y enviar la contestación dentro del término legal.',
                ],
                'processed_at' => Carbon::now()->subDays(2),
            ]
        );

        // --- Tareas repartidas en todos los estados ---
        $tasks = [
            // P1
            [$p1, 'Radicar contestación de demanda', 'alta', 'en_curso', $pick(0), 3],
            [$p1, 'Revisar pruebas aportadas', 'media', 'pendiente', $pick(1), 7],
            [$p1, 'Preparar alegatos de conclusión', 'alta', 'bloqueada', $pick(2), 10],
            [$p1, 'Notificar al cliente de la audiencia', 'urgente', 'completada', $pick(3), -1],
            // P2
            [$p2, 'Elaborar derecho de petición', 'media', 'pendiente', $pick(1), 5],
            [$p2, 'Solicitar historia laboral', 'baja', 'en_curso', $pick(2), 6],
            [$p2, 'Validar examen ocupacional', 'media', 'bloqueada', $pick(0), 8],
            [$p2, 'Archivar caso duplicado', 'baja', 'cancelada', $pick(3), null],
            [$p2, 'Enviar comunicación inicial al cliente', 'alta', 'completada', $pick(1), -2],
            // P3
            [$p3, 'Agendar visita preventiva', 'media', 'pendiente', $pick(2), 4],
            [$p3, 'Redactar minuta de contrato', 'alta', 'en_curso', $pick(0), 9],
            [$p3, 'Preparar material capacitación SARLAFT', 'baja', 'pendiente', $pick(3), 12],
            [$p3, 'Cierre y facturación', 'media', 'cancelada', $pick(1), null],
            [$p3, 'Dictamen jurídico laboral', 'urgente', 'en_curso', $pick(2), 2],
            [$p3, 'Subir documentos al portal del cliente', 'baja', 'completada', $pick(0), -3],
        ];

        foreach ($tasks as [$process, $titulo, $prioridad, $estado, $asignado, $diasLimite]) {
            Task::query()->firstOrCreate(
                ['process_id' => $process->id, 'titulo' => $titulo],
                [
                    'descripcion' => 'Tarea de demostración para el tablero Kanban. Estado inicial: '.$estado.'.',
                    'asignado_a' => $asignado,
                    'creado_por' => $creador,
                    'prioridad' => $prioridad,
                    'estado' => $estado,
                    'fecha_limite' => $diasLimite !== null ? Carbon::now()->addDays($diasLimite) : null,
                    'completada_at' => $estado === 'completada' ? Carbon::now()->subDays(1) : null,
                ]
            );
        }

        $this->command->info('DemoBoardSeeder: '.count($tasks).' tareas demo en 3 procesos.');
    }

    private function makeProcess(string $codigo, string $titulo, ?int $clientId, ?int $serviceId, string $estado): Process
    {
        return Process::query()->firstOrCreate(
            ['codigo' => $codigo],
            [
                'client_id' => $clientId,
                'service_type_id' => $serviceId,
                'titulo' => $titulo,
                'descripcion' => 'Proceso de demostración para el tablero.',
                'estado' => $estado,
                'fecha_apertura' => Carbon::now()->subDays(20),
            ]
        );
    }
}
