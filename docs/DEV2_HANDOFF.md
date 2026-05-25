# Handoff Dev 2 — Sprint 1 (rama `dev/ia-sprint1`)

## 1) AiService — firmas públicas (contrato)

Ubicación: `app/Services/AiService.php`

```php
public function generateDraft(string $prompt, ?string $systemPrompt = null, array $options = []): array
public function classifyEmail(string $subject, string $body, array $context = []): array
public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
```

### Estado actual
- `generateDraft()` → **implementado** (golpea `POST /v1/messages` real, devuelve texto + usage).
- `classifyEmail()` → lanza `\BadMethodCallException('not_implemented')`. Mockear en tests.
- `estimateCost()` → lanza `\BadMethodCallException('not_implemented')`. Mockear en tests.

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
```php
[
    'category'           => string,    // p.ej. "nuevo_caso", "seguimiento", "documento", ...
    'confidence'         => float,     // 0.0 - 1.0
    'matched_process_id' => int|null,  // null si no hay match
    'extracted'          => array,     // datos estructurados (cliente, fechas, refs, ...)
    'usage'              => ['input_tokens' => int, 'output_tokens' => int],
]
```

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
