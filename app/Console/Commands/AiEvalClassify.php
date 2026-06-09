<?php

namespace App\Console\Commands;

use App\Services\AiService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evalúa el prompt `resources/prompts/classify_email.md` contra el dataset dorado
 * de correos en `tests/fixtures/emails/` llamando a la API real de Anthropic.
 *
 * Uso:
 *   php artisan ai:eval-classify                  # corre todos los casos
 *   php artisan ai:eval-classify --filter=spam    # solo casos cuyo nombre contenga "spam"
 *   php artisan ai:eval-classify --model=claude-haiku-4-5
 */
class AiEvalClassify extends Command
{
    protected $signature = 'ai:eval-classify
                            {--filter= : Solo casos cuyo nombre contenga este texto}
                            {--model= : Modelo a evaluar (default: el de config/anthropic.php)}';

    protected $description = 'Evalúa el clasificador de correos IA contra el dataset dorado (llama a la API real)';

    public function handle(): int
    {
        $dir = base_path('tests/fixtures/emails');
        $context = json_decode(file_get_contents("{$dir}/_context.json"), true);
        $cases = json_decode(file_get_contents("{$dir}/cases.json"), true);

        if ($filter = $this->option('filter')) {
            $cases = array_values(array_filter($cases, fn ($c) => str_contains($c['name'], $filter)));
        }

        if (empty($cases)) {
            $this->warn('No hay casos que evaluar.');

            return self::SUCCESS;
        }

        $ai = $this->option('model')
            ? new AiService(model: $this->option('model'))
            : app(AiService::class);

        $this->info(sprintf('Evaluando %d casos contra la API…', count($cases)));
        $this->newLine();

        $pass = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $rows = [];
        $failures = [];

        foreach ($cases as $case) {
            try {
                $result = $ai->classifyEmail($case['input'], $context);
            } catch (Throwable $e) {
                $rows[] = [$case['name'], '💥 ERROR', $case['expect']['action'], '—', '—'];
                $failures[] = "{$case['name']}: excepción → {$e->getMessage()}";

                continue;
            }

            $inputTokens += $result['usage']['input_tokens'];
            $outputTokens += $result['usage']['output_tokens'];

            $errors = $this->check($case['expect'], $result);

            if (empty($errors)) {
                $pass++;
            } else {
                $failures[] = "{$case['name']}: ".implode(' | ', $errors);
            }

            $rows[] = [
                $case['name'],
                empty($errors) ? '✓' : '✗',
                $case['expect']['action'],
                $result['action'],
                number_format($result['confidence'], 2),
            ];
        }

        $this->table(['Caso', 'OK', 'Esperado', 'Obtenido', 'Conf.'], $rows);

        $total = count($cases);
        $accuracy = $total > 0 ? round(($pass / $total) * 100) : 0;

        $this->newLine();
        $this->line(sprintf('<options=bold>Resultado: %d/%d (%d%%)</>', $pass, $total, $accuracy));
        $this->line(sprintf(
            'Tokens: %s in / %s out · Costo estimado: $%.4f USD',
            number_format($inputTokens),
            number_format($outputTokens),
            $this->safeCost($ai, $inputTokens, $outputTokens),
        ));

        if (! empty($failures)) {
            $this->newLine();
            $this->warn('Fallos:');
            foreach ($failures as $f) {
                $this->line("  - {$f}");
            }
        }

        return $pass === $total ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Compara el resultado contra lo esperado y devuelve la lista de discrepancias.
     *
     * Claves soportadas en `expect`: action, process_code, client_name, service_type,
     * min_confidence, references_contains.
     *
     * @return string[]
     */
    protected function check(array $expect, array $result): array
    {
        $errors = [];

        if ($result['action'] !== $expect['action']) {
            $errors[] = "action: esperado '{$expect['action']}', obtenido '{$result['action']}'";
        }

        foreach (['process_code', 'client_name', 'service_type'] as $field) {
            if (isset($expect[$field]) && ($result[$field] ?? null) !== $expect[$field]) {
                $got = $result[$field] ?? '(omitido)';
                $errors[] = "{$field}: esperado '{$expect[$field]}', obtenido '{$got}'";
            }
        }

        if (isset($expect['min_confidence']) && $result['confidence'] < $expect['min_confidence']) {
            $errors[] = "confidence: esperado >= {$expect['min_confidence']}, obtenido {$result['confidence']}";
        }

        if (isset($expect['references_contains'])) {
            $refs = $result['extracted_fields']['references'] ?? [];
            $found = collect($refs)->contains(
                fn ($r) => str_contains((string) $r, $expect['references_contains'])
            );
            if (! $found) {
                $errors[] = "references: no contiene '{$expect['references_contains']}' (".json_encode($refs).')';
            }
        }

        return $errors;
    }

    protected function safeCost(AiService $ai, int $in, int $out): float
    {
        try {
            return $ai->estimateCost($in, $out, $this->option('model') ?: null);
        } catch (Throwable) {
            return 0.0;
        }
    }
}
