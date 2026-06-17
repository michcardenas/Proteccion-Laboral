<?php

use App\Models\EmailIngestion;
use Illuminate\Support\Str;

// Replica el query de la bandeja del board (sin restricción de usuario).
$emails = EmailIngestion::query()
    ->whereNotNull('process_id')
    ->with('process:id,codigo,titulo')
    ->latest('received_at')
    ->get()
    ->map(fn (EmailIngestion $e) => [
        'id' => $e->id,
        'subject' => $e->subject ?: '(sin asunto)',
        'respondido' => $e->respondido_at !== null,
        'proceso' => $e->process?->codigo,
        'preview_len' => Str::limit((string) $e->body_text, 400) ? strlen(Str::limit((string) $e->body_text, 400)) : 0,
    ]);

echo 'Correos en bandeja (con proceso): ' . $emails->count() . PHP_EOL;
foreach ($emails->take(5) as $e) {
    echo "  #{$e['id']} [{$e['proceso']}] " . ($e['respondido'] ? 'RESPONDIDO' : 'pendiente') . ' — ' . Str::limit($e['subject'], 50) . PHP_EOL;
}

// Probar marcar/desmarcar respondido en el primero.
$first = EmailIngestion::whereNotNull('process_id')->first();
if ($first) {
    $original = $first->respondido_at;
    $first->forceFill(['respondido_at' => now()])->save();
    echo 'Marcado respondido: ' . ($first->fresh()->respondido_at !== null ? 'OK' : 'FALLO') . PHP_EOL;
    $first->forceFill(['respondido_at' => $original])->save();
    echo 'Revertido al estado original (' . ($original ? $original : 'NULL') . ').' . PHP_EOL;
}

echo 'OK — bandeja y marca respondido funcionan.' . PHP_EOL;
