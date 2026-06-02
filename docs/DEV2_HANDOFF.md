# Handoff Dev 2 — Sprint 1 (rama `dev/ia-sprint1`)

## 1) AiService — firmas públicas (contrato)

Ubicación: `app/Services/AiService.php`

```php
public function generateDraft(string $prompt, ?string $systemPrompt = null, array $options = []): array
public function classifyEmail(array $payload, array $context = []): array
public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
```

> **Cambio de firma 2026-05-27 (D1-M1):** `classifyEmail()` ya no recibe `subject` + `body` posicionales; ahora toma un único array `$payload` con `from`, `subject`, `body_text`, `attachments`. La forma de retorno también cambió a la estructura del dominio legal (ver abajo). Si tienes código que aún usa la firma anterior, actualízalo.

### Estado actual
- `generateDraft()` → **implementado** (golpea `POST /v1/messages` real, devuelve texto + usage).
- `classifyEmail()` → **implementado**. Carga `resources/prompts/classify_email.md`, llama a Claude con `temperature: 0.0`, parsea JSON estricto (admite envoltura ```json…```) y fuerza `action = "requiere_revision_humana"` si `confidence < 0.6` o la acción no es válida.
- `estimateCost()` → **implementado**. Tabla `AiService::PRICING` por modelo (Sonnet/Opus/Haiku 4.x). Lanza `\InvalidArgumentException` si el modelo no está tarificado.

### Forma de retorno

**`generateDraft()`**
```php
[
    'text'        => string,
    'model'       => string,           // e.g. "claude-sonnet-4-6"
    'stop_reason' => string,           // "end_turn", "max_tokens", ...
    'usage'       => [
        'input_tokens'  => int,
        'output_tokens' => int,
    ],
]
```

**`classifyEmail()`**

Input:
```php
[
    'from'        => string,
    'subject'     => string,
    'body_text'   => string,
    'attachments' => string[],   // nombres o tipos MIME
]
// $context opcional: ['known_processes' => array<int, array{code,client_name,...}>]
```

Output:
```php
[
    'action'           => string,    // ver AiService::CLASSIFY_ACTIONS
    'confidence'       => float,     // 0.0 - 1.0
    'process_code'?    => string,    // si hay match con known_processes
    'client_name'?     => string,
    'service_type'?    => string,
    'stage_hint'?      => string,    // etapa procesal sugerida
    'summary'          => string,    // resumen 1-2 frases
    'extracted_fields' => [
        'dates'      => string[],
        'amounts'    => string[],
        'references' => string[],
        'people'     => string[],
    ],
    'usage' => ['input_tokens' => int, 'output_tokens' => int],
]
```

Acciones válidas: `nuevo_caso`, `seguimiento_proceso`, `documento_recibido`, `comunicacion_cliente`, `spam_o_irrelevante`, `requiere_revision_humana`.

**`estimateCost()`** → `float` (USD).

### Cómo instanciar
```php
$ai = app(\App\Services\AiService::class);
// o
$ai = new \App\Services\AiService();
```

Lee config de `config/anthropic.php`.

---

## 2) EmailIngestion — modelo y tabla

Ubicación: `app/Models/EmailIngestion.php`
Migración: `database/migrations/2026_05_25_215309_create_email_ingestions_table.php` (ya migrada).

### Campos
| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `message_id` | string **unique** | id del mensaje en Gmail (idempotencia) |
| `from` | string | |
| `to` | string | |
| `subject` | string | |
| `received_at` | timestamp | cast: `datetime` |
| `raw_payload` | json | cast: `array` |
| `body_text` | text | |
| `status` | enum | `pending`, `classified`, `processed`, `needs_review`, `failed` |
| `process_id` | FK nullable → `processes` | `nullOnDelete` |
| `ai_classification` | json nullable | cast: `array` |
| `error` | text nullable | |
| `processed_at` | timestamp nullable | cast: `datetime` |

### Constantes de estado (úsalas, no strings)
```php
EmailIngestion::STATUS_PENDING
EmailIngestion::STATUS_CLASSIFIED
EmailIngestion::STATUS_PROCESSED
EmailIngestion::STATUS_NEEDS_REVIEW
EmailIngestion::STATUS_FAILED
```

### Relación
```php
$ingestion->process // BelongsTo App\Models\Process
```

---

## 3) Config Anthropic

`config/anthropic.php` y `.env`:
- `ANTHROPIC_API_KEY` (no commitear)
- `ANTHROPIC_MODEL=claude-sonnet-4-6`
- `ANTHROPIC_MAX_TOKENS=4096`
- `ANTHROPIC_TIMEOUT=60`

---

## 4) Tests

Suite: `php artisan test --filter=AiServiceTest`. Usa `Http::fake()` — no consume API real.
