<?php

namespace App\Console\Commands;

use App\Models\AiGeneration;
use App\Models\ChecklistResponse;
use App\Models\Client;
use App\Models\Process;
use App\Services\AiService;
use App\Services\DocumentTextExtractor;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Demo end-to-end del Portal del Cliente para GPT SEGUROS:
 * crea un proceso, interpreta con IA el plan/contrato reales (carpeta U),
 * vuelca etapas/entregables/tareas y activa el portal. Imprime credenciales.
 *
 * Uso: php artisan demo:gpt-portal
 */
class DemoGptPortal extends Command
{
    protected $signature = 'demo:gpt-portal
        {--nit=901555888 : NIT para el login del portal}
        {--password=GptDemo2026 : Contraseña del portal}
        {--no-ai : Saltar la IA y usar solo la plantilla del servicio}';

    protected $description = 'Arma un ejemplo completo del portal del cliente (GPT SEGUROS) con IA';

    private const CLIENT_ID = 8;        // GPT SEGUROS

    private const SERVICE_TYPE_ID = 7;  // Diagnóstico e Implementación Laboral

    private const ABOGADO_ID = 3;       // Leidy (abogado_interno)

    private const DEMO_CODE = 'PL-GPT-DEMO';

    public function handle(AiService $ai, DocumentTextExtractor $extractor): int
    {
        $client = Client::find(self::CLIENT_ID);
        if (! $client) {
            $this->error('No existe el cliente GPT SEGUROS (id 8).');

            return self::FAILURE;
        }

        // 1) Proceso demo (idempotente por código).
        $process = Process::firstOrNew(['codigo' => self::DEMO_CODE]);
        $process->fill([
            'client_id' => self::CLIENT_ID,
            'service_type_id' => self::SERVICE_TYPE_ID,
            'abogado_lider_id' => self::ABOGADO_ID,
            'titulo' => 'Diagnóstico e Implementación Laboral - GPT Consultores',
            'descripcion' => 'Proceso de demostración del portal del cliente.',
            'estado' => 'abierto',
            'fecha_apertura' => $process->fecha_apertura ?? now()->startOfDay(),
        ])->save();
        $this->info("Proceso {$process->codigo} (id {$process->id}) listo para GPT SEGUROS.");

        // 2) Plan de trabajo.
        if ($this->option('no-ai')) {
            $this->warn('Modo sin IA: usa el comando del seeder/plantilla aparte. Saltando extracción.');
        } else {
            $plan = $this->extraerPlanConIA($ai, $extractor, $process);
            if ($plan === null) {
                return self::FAILURE;
            }
            $this->aplicarPlan($process, $plan);
        }

        // 3) NIT + activar portal.
        $nit = (string) $this->option('nit');
        $password = (string) $this->option('password');
        $client->forceFill([
            'nit' => $nit,
            'portal_activo' => true,
            'password' => $password, // cast 'hashed' lo cifra
        ])->save();

        $this->newLine();
        $this->info('==================== PORTAL LISTO ====================');
        $this->line('  URL:      http://127.0.0.1:8000/portal/login');
        $this->line("  NIT:      {$nit}");
        $this->line("  Password: {$password}");
        $this->line("  Cliente:  {$client->razon_social}");
        $this->line('  Puede acceder: '.($client->fresh()->puedeAccederPortal() ? 'SÍ ✓' : 'NO ✗'));
        $this->info('======================================================');

        return self::SUCCESS;
    }

    private function extraerPlanConIA(AiService $ai, DocumentTextExtractor $extractor, Process $process): ?array
    {
        $dir = 'C:/Users/elbub/Downloads/U';
        $archivos = [
            $dir.'/PLAN DE TRABAJO GPT CONSULTORES EN RIESGOS Y ASESORES EN SEGUROS.docx',
            $dir.'/CONTRATO PROTECCION LABORAL.pdf',
        ];

        $textos = [];
        foreach ($archivos as $ruta) {
            if (! is_file($ruta)) {
                $this->warn("No encontrado: {$ruta}");

                continue;
            }
            try {
                $file = new UploadedFile($ruta, basename($ruta), null, null, true);
                $texto = $extractor->extract($file);
                $textos[] = '===== '.basename($ruta)." =====\n".$texto;
                $this->line('  Texto extraído de '.basename($ruta).' ('.mb_strlen($texto).' chars)');
            } catch (\Throwable $e) {
                $this->warn('No se pudo leer '.basename($ruta).': '.$e->getMessage());
            }
        }

        if (empty($textos)) {
            $this->error('No se pudo extraer texto de ningún documento.');

            return null;
        }

        $this->line('  Llamando a Claude (extractWorkPlan)...');
        try {
            $extraction = $ai->extractWorkPlan(implode("\n\n", $textos), [
                'today' => now()->toDateString(),
                'process_code' => $process->codigo,
                'client_name' => $process->client?->razon_social,
                'service_type' => $process->serviceType?->nombre,
                'fecha_apertura' => $process->fecha_apertura?->toDateString(),
            ]);
        } catch (\Throwable $e) {
            $this->error('La IA falló: '.$e->getMessage());

            return null;
        }

        $cost = $ai->estimateCost($extraction['usage']['input_tokens'], $extraction['usage']['output_tokens']);
        AiGeneration::create([
            'user_id' => self::ABOGADO_ID,
            'contexto_tipo' => Process::class,
            'contexto_id' => $process->id,
            'proveedor' => 'anthropic',
            'modelo' => config('anthropic.model'),
            'request_hash' => $extraction['request_hash'],
            'prompt' => 'demo:gpt-portal extract_work_plan',
            'respuesta' => json_encode($extraction, JSON_UNESCAPED_UNICODE),
            'tokens_in' => $extraction['usage']['input_tokens'],
            'tokens_out' => $extraction['usage']['output_tokens'],
            'latencia_ms' => $extraction['latencia_ms'],
            'costo_usd' => $cost,
            'estado' => 'ok',
        ]);

        $this->info(sprintf(
            '  IA OK: %s | %d etapas, %d transversales, %d tareas | $%.4f USD',
            $extraction['tipo_documento'],
            count($extraction['etapas']),
            count($extraction['transversales']),
            count($extraction['tareas']),
            $cost
        ));
        $this->line('  Resumen: '.mb_strimwidth((string) ($extraction['resumen'] ?? ''), 0, 160, '…'));

        return $extraction;
    }

    /** Replica PlanImportController::apply (reemplazar_plan = true). */
    private function aplicarPlan(Process $process, array $plan): void
    {
        DB::transaction(function () use ($process, $plan) {
            $process->stages()->delete();

            foreach (array_values($plan['etapas']) as $i => $etapa) {
                $stage = $process->stages()->create([
                    'orden' => $i + 1,
                    'nombre' => $etapa['nombre'],
                    'descripcion' => $etapa['descripcion'] ?? null,
                    'estado' => 'pendiente',
                    'fecha_limite' => $etapa['fecha_entrega'] ?? null,
                ]);

                foreach (($etapa['entregables'] ?? []) as $entregable) {
                    if (trim((string) $entregable) === '') {
                        continue;
                    }
                    ChecklistResponse::create([
                        'process_stage_id' => $stage->id,
                        'descripcion' => $entregable,
                        'es_obligatorio' => true,
                    ]);
                }

                if ($i === 0) {
                    foreach (($plan['transversales'] ?? []) as $transversal) {
                        if (trim((string) $transversal) === '') {
                            continue;
                        }
                        ChecklistResponse::create([
                            'process_stage_id' => $stage->id,
                            'descripcion' => $transversal,
                            'es_obligatorio' => false,
                        ]);
                    }
                }
            }

            $process->tasks()->delete();
            foreach (($plan['tareas'] ?? []) as $tarea) {
                $process->tasks()->create([
                    'titulo' => $tarea['titulo'],
                    'descripcion' => $tarea['descripcion'] ?? null,
                    'prioridad' => $tarea['prioridad'] ?? 'media',
                    'estado' => 'pendiente',
                    'creado_por' => self::ABOGADO_ID,
                    'fecha_limite' => $tarea['fecha_limite'] ?? null,
                ]);
            }
        });

        $this->info('  Plan aplicado al proceso (etapas + entregables + tareas).');
    }
}
