<?php

namespace App\Console\Commands;

use App\Models\AiGeneration;
use App\Models\Client;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rellena la ficha del cliente con lo que ya dice su resumen documental.
 *
 * Los clientes importados de Drive entraron solo con el nombre de la carpeta:
 * sin NIT, sin ciudad, sin sector. Y luego la IA escribe una ficha de veinte
 * mil caracteres que empieza diciendo el NIT, la ciudad y el sector. La
 * plataforma tenia el dato y lo enseñaba en un sitio donde no se puede filtrar
 * ni buscar, mientras la cabecera del cliente parecia vacia.
 *
 * NO pisa lo que ya tenga valor. Un dato escrito por una persona vale mas que
 * uno deducido de un PDF, aunque el deducido sea correcto.
 */
class ClientesCompletarPerfil extends Command
{
    protected $signature = 'clientes:completar-perfil
        {--client= : Solo este cliente (id)}
        {--ejecutar : Guarda de verdad. Sin esto solo muestra lo que haria}';

    protected $description = 'Extrae NIT, ciudad, sector y contacto de la ficha de conocimiento y completa los campos vacios del cliente';

    /** Solo estos. El resto de la ficha es prosa y no cabe en una columna. */
    private const CAMPOS = ['nit', 'dv', 'ciudad', 'sector', 'contacto_principal', 'email', 'telefono'];

    public function handle(AiService $ai): int
    {
        $clientes = Client::query()
            ->whereNotNull('resumen_documental')
            ->when($this->option('client'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($clientes->isEmpty()) {
            $this->warn('Ningun cliente tiene ficha de conocimiento todavia. Corre antes drive:sync-knowledge.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('ejecutar');
        $filas = [];

        foreach ($clientes as $cliente) {
            try {
                $datos = $this->extraer($ai, $cliente);
            } catch (Throwable $e) {
                $this->error("{$cliente->razon_social}: ".Str::limit($e->getMessage(), 100));

                continue;
            }

            $cambios = [];

            foreach (self::CAMPOS as $campo) {
                $valor = trim((string) ($datos[$campo] ?? ''));

                // Vacio, o el modelo diciendo que no lo encontro.
                if ($valor === '' || in_array(mb_strtolower($valor), ['null', 'n/a', 'no indicado', 'no especificado'], true)) {
                    continue;
                }

                // Lo que ya tiene valor no se toca.
                if (filled($cliente->{$campo})) {
                    continue;
                }

                $cambios[$campo] = Str::limit($valor, $campo === 'nit' ? 30 : 150, '');
            }

            if ($cambios === []) {
                $filas[] = [$cliente->razon_social, '—', 'ya estaba completo'];

                continue;
            }

            if ($aplicar) {
                $cliente->forceFill($cambios)->save();
            }

            foreach ($cambios as $campo => $valor) {
                $filas[] = [$cliente->razon_social, $campo, Str::limit($valor, 60)];
            }
        }

        $this->newLine();
        $this->table(['Cliente', 'Campo', 'Valor'], $filas);

        if (! $aplicar) {
            $this->info('Nada se guardo. Repite con --ejecutar.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    protected function extraer(AiService $ai, Client $cliente): array
    {
        // Con la cabecera de la ficha basta: la identidad va siempre arriba y
        // mandar veinte mil caracteres para sacar siete campos es tirar dinero.
        $ficha = mb_substr((string) $cliente->resumen_documental, 0, 6000);

        $prompt = <<<PROMPT
        De la siguiente ficha de un cliente, extrae SOLO estos campos y devuelve
        UNICAMENTE un objeto JSON, sin texto alrededor y sin bloque de codigo:

        {"nit": "", "dv": "", "ciudad": "", "sector": "", "contacto_principal": "", "email": "", "telefono": ""}

        Reglas:
        - `nit` sin el digito de verificacion; el digito va aparte en `dv`.
        - `ciudad` solo el municipio, sin departamento.
        - `sector` en pocas palabras (ej. "Molineria de arroz").
        - `contacto_principal` el representante legal, nombre completo.
        - Si un dato NO aparece en la ficha, deja la cadena vacia. No lo inventes
          ni lo deduzcas de nombres parecidos.

        FICHA:
        {$ficha}
        PROMPT;

        $respuesta = $ai->generateDraft($prompt, null, ['temperature' => 0.0, 'max_tokens' => 500]);

        $this->registrar($ai, $cliente, $respuesta);

        $texto = trim((string) ($respuesta['text'] ?? ''));
        $texto = preg_replace('/^```(?:json)?|```$/m', '', $texto) ?? $texto;

        $datos = json_decode(trim($texto), true);

        if (! is_array($datos)) {
            throw new \RuntimeException('La respuesta no era JSON: '.Str::limit($texto, 120));
        }

        return $datos;
    }

    /** @param  array<string, mixed>  $respuesta */
    protected function registrar(AiService $ai, Client $cliente, array $respuesta): void
    {
        try {
            $costo = $ai->estimateCost(
                $respuesta['usage']['input_tokens'] ?? 0,
                $respuesta['usage']['output_tokens'] ?? 0,
                $respuesta['model'] ?? null,
            );
        } catch (Throwable) {
            $costo = 0.0;
        }

        try {
            AiGeneration::create([
                'user_id' => null,
                'contexto_tipo' => Client::class,
                'contexto_id' => $cliente->id,
                'proveedor' => 'anthropic',
                'modelo' => $respuesta['model'] ?? config('anthropic.model'),
                'request_hash' => $respuesta['request_hash'] ?? null,
                'prompt' => 'completar_perfil: '.Str::limit($cliente->razon_social, 120),
                'respuesta' => (string) ($respuesta['text'] ?? ''),
                'tokens_in' => $respuesta['usage']['input_tokens'] ?? 0,
                'tokens_out' => $respuesta['usage']['output_tokens'] ?? 0,
                'latencia_ms' => $respuesta['latencia_ms'] ?? 0,
                'costo_usd' => $costo,
                'estado' => 'ok',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
