<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Process;
use App\Models\User;
use App\Services\ProcessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Crea (o resetea) un proceso de prueba LIMPIO para demostrar en vivo el
 * flujo de avance de etapas: palomear entregables -> "Completar etapa" ->
 * el sistema valida obligatorios y avanza la siguiente etapa solo.
 *
 * Usa un cliente demo dedicado para NO ensuciar el portal de clientes reales.
 * Es idempotente: re-correrlo borra las etapas/tareas y las regenera en cero.
 *
 * Uso: php artisan demo:proceso-etapas
 *      php artisan demo:proceso-etapas --service=2 --abogado=1
 */
class DemoProcesoEtapas extends Command
{
    protected $signature = 'demo:proceso-etapas
        {--service=3 : ID del ServiceType (3 = Representación Judicial, 5 etapas)}
        {--abogado=1 : ID del User abogado líder (1 = Carlos, director)}
        {--codigo=PL-DEMO-ETAPAS : Código del proceso de demo}';

    protected $description = 'Crea/resetea un proceso de prueba para demostrar el avance de etapas';

    public function handle(ProcessService $processService): int
    {
        $abogadoId = (int) $this->option('abogado');
        $serviceId = (int) $this->option('service');
        $codigo = (string) $this->option('codigo');

        $abogado = User::find($abogadoId);
        if (! $abogado) {
            $this->error("No existe el User abogado id {$abogadoId}.");

            return self::FAILURE;
        }

        // Cliente demo dedicado (no toca clientes reales ni sus portales).
        $client = Client::firstOrCreate(
            ['razon_social' => 'CLIENTE DEMO - ETAPAS'],
            [
                'nit' => '999000111',
                'email' => 'demo-etapas@example.test',
                'estado' => 'activo',
                'fecha_alta' => now()->toDateString(),
            ]
        );

        // Login del abogado para que las tareas autogeneradas queden a su nombre.
        Auth::login($abogado);

        // Reseteo idempotente: si ya existe (incluso soft-deleted), borra hijos y el proceso.
        $existing = Process::withTrashed()->where('codigo', $codigo)->first();
        if ($existing) {
            DB::transaction(function () use ($existing) {
                foreach ($existing->stages as $stage) {
                    $stage->checklistResponses()->delete();
                }
                $existing->stages()->delete();
                $existing->tasks()->delete();
                $existing->forceDelete();
            });
            $this->line("Proceso previo {$existing->codigo} eliminado (reset).");
        }

        // Crea el proceso y clona etapas + entregables + tareas desde la plantilla del servicio.
        $process = $processService->createFromTemplate([
            'client_id' => $client->id,
            'service_type_id' => $serviceId,
            'abogado_lider_id' => $abogadoId,
            'codigo' => $codigo,
            'titulo' => 'Proceso DEMO - Avance de etapas',
            'descripcion' => 'Proceso de prueba para demostrar en vivo cómo el abogado completa y avanza etapas.',
            'estado' => 'abierto',
            'fecha_apertura' => now()->startOfDay(),
        ]);

        $process->load(['serviceType', 'stages.checklistResponses', 'abogadoLider']);

        $this->newLine();
        $this->info('==================== PROCESO DEMO LISTO ====================');
        $this->line("  Código:        {$process->codigo} (id {$process->id})");
        $this->line("  Servicio:      {$process->serviceType->nombre}");
        $this->line("  Abogado líder: {$process->abogadoLider->name}");
        $this->line("  Cliente:       {$client->razon_social}");
        $this->line("  Estado:        {$process->estado}");
        $this->newLine();
        $this->line('  Etapas (todas en pendiente, listas para avanzar):');
        foreach ($process->stages->sortBy('orden') as $stage) {
            $obl = $stage->checklistResponses->where('es_obligatorio', true)->count();
            $this->line("   {$stage->orden}. {$stage->nombre}  [{$stage->estado}]  entregables obligatorios: {$obl}");
        }
        $this->newLine();
        $this->line("  Ábrelo en:  http://127.0.0.1:8000/admin/processes/{$process->id}");
        $this->info('===========================================================');
        $this->newLine();
        $this->line('  Flujo a demostrar:');
        $this->line('   1) Palomea los entregables de la etapa 1 (al primer check pasa a "en_curso").');
        $this->line('   2) Clic en "✓ Completar etapa". Si falta algún obligatorio, te bloquea.');
        $this->line('   3) Al completarla, la etapa 2 pasa sola a "en_curso". Repite.');
        $this->line('   (Re-corre este comando cuando quieras dejar la demo en cero.)');

        return self::SUCCESS;
    }
}
