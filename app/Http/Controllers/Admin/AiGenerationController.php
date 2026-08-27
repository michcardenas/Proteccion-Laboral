<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Process;
use App\Models\User;
use App\Services\AiService;
use App\Services\ProcessContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiGenerationController extends Controller
{
    /**
     * Plantillas disponibles en resources/prompts/ que el controller puede usar para drafts.
     */
    public const ALLOWED_TEMPLATES = [
        'draft_demanda',
        'draft_respuesta',
        'draft_dictamen',
        'draft_comunicacion_cliente',
    ];

    public function __construct(private readonly AiService $ai) {}

    /**
     * GET /admin/ai/playground
     * Vista de pruebas: lista procesos del usuario y deja generar borradores
     * eligiendo plantilla. Útil para QA y demos.
     */
    public function playground(Request $request): Response
    {
        abort_unless($request->user()?->can('ai.use'), 403);

        $user = $request->user();

        $processes = Process::query()
            ->with(['client:id,razon_social', 'serviceType:id,nombre'])
            ->when(! $user->can('processes.view'), function ($q) use ($user) {
                $q->where(function ($qq) use ($user) {
                    $qq->where('abogado_lider_id', $user->id)
                        ->orWhere('apoderado_id', $user->id)
                        ->orWhere('coordinador_id', $user->id);
                });
            })
            ->latest('fecha_apertura')
            ->limit(50)
            ->get()
            ->map(fn (Process $p) => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'titulo' => $p->titulo,
                'client_name' => $p->client?->razon_social,
                'service_type' => $p->serviceType?->nombre,
            ]);

        return Inertia::render('Admin/Ai/Playground', [
            'processes' => $processes,
            'templates' => self::ALLOWED_TEMPLATES,
        ]);
    }

    /**
     * POST /admin/processes/{process}/ai/generate
     * Genera un borrador IA con una plantilla, lo persiste en ai_generations
     * y devuelve el texto al frontend en JSON.
     */
    public function store(Request $request, Process $process): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);

        // La generación de un borrador legal puede tardar 30-90s con Sonnet y max_tokens altos.
        // El default de PHP (max_execution_time = 60) nos cortaría con fatal error.
        // Lo subimos a 180s solo para esta request.
        set_time_limit(180);

        $validated = $request->validate([
            'template' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_TEMPLATES)],
            'placeholders' => ['sometimes', 'array'],
            'placeholders.*' => ['nullable', 'string'],
        ]);

        // Columnas completas: el ProcessContextBuilder necesita NIT/sector/ciudad del cliente
        // y loadMissing no recargaría la relación si ya viniera con columnas restringidas.
        $process->loadMissing(['client', 'serviceType']);

        $prompt = $this->renderTemplate(
            template: $validated['template'],
            process: $process,
            overrides: $validated['placeholders'] ?? [],
        );

        try {
            $result = $this->ai->generateDraft($prompt);

            $cost = $this->ai->estimateCost(
                $result['usage']['input_tokens'],
                $result['usage']['output_tokens'],
                $result['model'],
            );

            $generation = AiGeneration::create([
                'user_id' => Auth::id(),
                'contexto_tipo' => Process::class,
                'contexto_id' => $process->id,
                'proveedor' => 'anthropic',
                'modelo' => $result['model'],
                'request_hash' => $result['request_hash'],
                'prompt' => $prompt,
                'respuesta' => $result['text'],
                'tokens_in' => $result['usage']['input_tokens'],
                'tokens_out' => $result['usage']['output_tokens'],
                'latencia_ms' => $result['latencia_ms'],
                'costo_usd' => $cost,
                'estado' => 'ok',
            ]);

            return response()->json([
                'id' => $generation->id,
                'borrador' => $result['text'],
                'modelo' => $result['model'],
                'tokens' => $result['usage'],
                'costo_usd' => $cost,
                'latencia_ms' => $result['latencia_ms'],
            ]);
        } catch (Throwable $e) {
            // Persistir el fallo para análisis posterior
            AiGeneration::create([
                'user_id' => Auth::id(),
                'contexto_tipo' => Process::class,
                'contexto_id' => $process->id,
                'proveedor' => 'anthropic',
                'modelo' => config('anthropic.model'),
                'prompt' => $prompt,
                'estado' => 'error',
                'error_mensaje' => $e->getMessage(),
            ]);

            report($e);

            return response()->json([
                'error' => 'No se pudo generar el borrador.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Tipos válidos para la columna `tipo` de la tabla documents.
     */
    public const DOCUMENT_TYPES = [
        'contrato', 'concepto', 'informe', 'escrito', 'comunicacion', 'soporte', 'otro',
    ];

    /**
     * POST /admin/processes/{process}/ai/document
     * Persiste un borrador IA (texto editado) como Document HTML vinculado al proceso.
     */
    public function storeAsDocument(Request $request, Process $process): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);

        $validated = $request->validate([
            'contenido' => ['required', 'string'],
            'nombre' => ['nullable', 'string', 'max:200'],
            'tipo' => ['nullable', 'string', 'in:'.implode(',', self::DOCUMENT_TYPES)],
            'visible_cliente' => ['sometimes', 'boolean'],
        ]);

        $html = $this->wrapAsHtml(
            $validated['nombre'] ?? "Borrador IA — {$process->codigo}",
            $validated['contenido'],
        );

        $ruta = "documents/process_{$process->id}/".Str::uuid()->toString().'.html';
        Storage::disk('local')->put($ruta, $html);

        $document = Document::create([
            'process_id' => $process->id,
            'client_id' => $process->client_id,
            'nombre' => $validated['nombre'] ?? "Borrador IA — {$process->codigo} — ".now()->format('Y-m-d'),
            'ruta' => $ruta,
            'disco' => 'local',
            'tipo' => $validated['tipo'] ?? 'escrito',
            'mime' => 'text/html',
            'tamano_bytes' => strlen($html),
            'generado_por_ia' => true,
            'subido_por' => Auth::id(),
            'visible_cliente' => $validated['visible_cliente'] ?? false,
        ]);

        return response()->json([
            'id' => $document->id,
            'nombre' => $document->nombre,
            'tipo' => $document->tipo,
            'generado_por_ia' => $document->generado_por_ia,
            'visible_cliente' => $document->visible_cliente,
            'created_at' => $document->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * POST /admin/processes/{process}/ai/comment
     * Persiste un borrador IA (texto editado) como Comment polimórfico del proceso.
     */
    public function storeAsComment(Request $request, Process $process): JsonResponse
    {
        abort_unless($request->user()?->can('ai.use'), 403);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'visible_cliente' => ['sometimes', 'boolean'],
        ]);

        $comment = $process->comments()->create([
            'user_id' => Auth::id(),
            'body' => $validated['body'],
            'visible_cliente' => $validated['visible_cliente'] ?? false,
        ]);

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'visible_cliente' => $comment->visible_cliente,
            'user' => $request->user()?->name,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Envuelve un texto plano en un documento HTML mínimo, preservando saltos de línea.
     */
    protected function wrapAsHtml(string $title, string $contenido): string
    {
        $safeTitle = e($title);
        $body = nl2br(e($contenido));

        return <<<HTML
        <!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta name="generator" content="Protección Laboral · IA">
            <title>{$safeTitle}</title>
        </head>
        <body>
        {$body}
        </body>
        </html>
        HTML;
    }

    /**
     * GET /admin/ai/usage
     * Lista las generaciones IA del mes en curso con sus costos y agregados.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ai.usage_view'), 403);

        // Mes seleccionado (formato Y-m); por defecto el mes en curso.
        $mesParam = $request->query('mes');
        try {
            $base = $mesParam
                ? Carbon::createFromFormat('Y-m', $mesParam)->startOfMonth()
                : now()->startOfMonth();
        } catch (Throwable $e) {
            $base = now()->startOfMonth();
        }

        $now = $base;
        $startOfMonth = $base->copy()->startOfMonth();
        $endOfMonth = $base->copy()->endOfMonth();

        $userId = $request->integer('user_id') ?: null;
        $modelo = $request->query('modelo') ?: null;

        // Filtros comunes a las 3 consultas (paginada, stats y última).
        $applyFilters = fn ($query) => $query
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($modelo, fn ($q) => $q->where('modelo', $modelo));

        $generations = $applyFilters(AiGeneration::query())
            ->with('user:id,name')
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AiGeneration $g) => [
                'id' => $g->id,
                'user' => $g->user?->name,
                'modelo' => $g->modelo,
                'request_hash' => $g->request_hash,
                'tokens_in' => $g->tokens_in,
                'tokens_out' => $g->tokens_out,
                'costo_usd' => (float) $g->costo_usd,
                'latencia_ms' => $g->latencia_ms,
                'estado' => $g->estado,
                'error_mensaje' => $g->error_mensaje,
                'contexto_tipo' => $g->contexto_tipo,
                'contexto_id' => $g->contexto_id,
                'prompt' => $g->prompt,
                'respuesta' => $g->respuesta,
                'respuesta_preview' => $g->respuesta
                    ? Str::limit(strip_tags($g->respuesta), 280, '…')
                    : null,
                'created_at' => $g->created_at?->toIso8601String(),
            ]);

        $stats = $applyFilters(AiGeneration::query())
            ->selectRaw("
                SUM(costo_usd) as costo_total,
                SUM(tokens_in) as tokens_in_total,
                SUM(tokens_out) as tokens_out_total,
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'ok' THEN 1 ELSE 0 END) as total_ok,
                SUM(CASE WHEN estado = 'error' THEN 1 ELSE 0 END) as total_error,
                AVG(latencia_ms) as latencia_promedio,
                MAX(latencia_ms) as latencia_max
            ")
            ->first();

        // Última generación exitosa (para destacarla como showcase)
        $ultima = $applyFilters(AiGeneration::query())
            ->where('estado', 'ok')
            ->with('user:id,name')
            ->latest()
            ->first();

        // Opciones para los selectores de filtro.
        $filterOptions = [
            'usuarios' => User::query()
                ->whereIn('id', AiGeneration::query()->select('user_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name']),
            'modelos' => AiGeneration::query()
                ->whereNotNull('modelo')
                ->distinct()
                ->orderBy('modelo')
                ->pluck('modelo'),
            // Lista de meses (Y-m) con datos. Se calcula en PHP para ser portable
            // entre motores (MySQL en prod, SQLite en tests).
            'meses' => AiGeneration::query()
                ->orderByDesc('created_at')
                ->pluck('created_at')
                ->map(fn ($d) => $d?->format('Y-m'))
                ->filter()
                ->unique()
                ->values(),
        ];

        return Inertia::render('Admin/AiUsage/Index', [
            'generations' => $generations,
            'stats' => [
                'costo_total' => (float) ($stats->costo_total ?? 0),
                'tokens_in_total' => (int) ($stats->tokens_in_total ?? 0),
                'tokens_out_total' => (int) ($stats->tokens_out_total ?? 0),
                'total' => (int) ($stats->total ?? 0),
                'total_ok' => (int) ($stats->total_ok ?? 0),
                'total_error' => (int) ($stats->total_error ?? 0),
                'latencia_promedio' => (int) round((float) ($stats->latencia_promedio ?? 0)),
                'latencia_max' => (int) ($stats->latencia_max ?? 0),
                'mes' => $now->format('Y-m'),
                'mes_nombre' => $now->locale('es')->isoFormat('MMMM YYYY'),
            ],
            'ultima' => $ultima ? [
                'id' => $ultima->id,
                'user' => $ultima->user?->name,
                'modelo' => $ultima->modelo,
                'tokens_in' => $ultima->tokens_in,
                'tokens_out' => $ultima->tokens_out,
                'costo_usd' => (float) $ultima->costo_usd,
                'latencia_ms' => $ultima->latencia_ms,
                'respuesta' => $ultima->respuesta,
                'request_hash' => $ultima->request_hash,
                'created_at' => $ultima->created_at?->toIso8601String(),
            ] : null,
            'filters' => [
                'mes' => $now->format('Y-m'),
                'user_id' => $userId,
                'modelo' => $modelo,
            ],
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Carga la plantilla y reemplaza los placeholders con datos del proceso + overrides del request.
     */
    protected function renderTemplate(string $template, Process $process, array $overrides): string
    {
        $path = resource_path("prompts/{$template}.md");
        abort_if(! is_file($path), 422, "Plantilla {$template} no encontrada.");

        $contexto = app(ProcessContextBuilder::class)->build($process);

        $defaults = [
            'process_code' => $process->codigo ?? '',
            'client_name' => $process->client?->razon_social ?? '',
            'service_type' => $process->serviceType?->nombre ?? '',
        ];

        $placeholders = array_merge($defaults, $overrides);
        // El contexto del expediente lo arma el sistema y siempre gana sobre cualquier override.
        $placeholders['expediente_contexto'] = $contexto;

        $replacements = [];
        foreach ($placeholders as $key => $value) {
            $replacements["{{{$key}}}"] = (string) ($value ?? '');
        }

        $contenido = file_get_contents($path);
        $rendered = strtr($contenido, $replacements);

        // Red de seguridad: si la plantilla no incluye el placeholder {{expediente_contexto}}
        // (p. ej. una plantilla nueva sin actualizar), anexamos el contexto para no perderlo.
        if (! str_contains($contenido, '{{expediente_contexto}}') && trim($contexto) !== '') {
            $rendered .= "\n\n---\n\n".$contexto;
        }

        return $rendered;
    }
}
