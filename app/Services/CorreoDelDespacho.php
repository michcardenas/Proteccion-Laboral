<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Document;
use App\Models\EmailIngestion;
use App\Models\IntegrationToken;
use App\Models\Process;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Los correos que manda el propio despacho (respuestas, envíos de documentos)
 * y que entran a la bandeja conectada, por copia o reenvío.
 *
 * Antes se trataban como si los hubiera escrito el cliente: una nota interna
 * con el resumen de la IA y los adjuntos como soporte oculto. El cliente no
 * veía el Word que se le había mandado y la abogada tenía que volver a
 * subirlo a mano. Ahora quedan como mensaje del despacho, con el texto real
 * del correo y sus adjuntos colgados de él (Word primero en el portal).
 */
class CorreoDelDespacho
{
    /** Imágenes más pequeñas que esto son logos de firma, no adjuntos. */
    private const IMAGEN_MINIMA_BYTES = 30 * 1024;

    private const PROVEEDORES_PUBLICOS = ['gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com', 'yahoo.com', 'icloud.com'];

    private ?Collection $correosEquipo = null;

    private ?Collection $dominios = null;

    public function esDelDespacho(EmailIngestion $ingestion): bool
    {
        $from = self::soloCorreo($ingestion->from);
        if ($from === '') {
            return false;
        }

        return $this->correosEquipo()->contains($from)
            || $this->dominios()->contains(substr(strrchr($from, '@'), 1));
    }

    /**
     * Se comparte solo si el cliente está entre los destinatarios. Un reenvío
     * a la bandeja o un correo interno queda para el equipo, que lo comparte
     * con un clic (y con él sus adjuntos).
     */
    public function vaDirigidoAlCliente(EmailIngestion $ingestion, Process $process): bool
    {
        $destinatarios = collect(preg_split('/[,;]/', (string) $ingestion->to))->map(fn ($d) => self::soloCorreo($d))->filter();

        return $destinatarios->intersect($process->client?->correos() ?? collect())->isNotEmpty();
    }

    /**
     * Crea (una sola vez) el mensaje del despacho con sus adjuntos.
     *
     * @return Comment|null null si ya existía
     */
    public function registrar(Process $process, EmailIngestion $ingestion): ?Comment
    {
        if ($process->comments()->where('email_ingestion_id', $ingestion->id)->exists()) {
            return null;
        }

        $comment = $process->comments()->create([
            'user_id' => $this->autor($ingestion, $process),
            'email_ingestion_id' => $ingestion->id,
            'body' => $this->cuerpo($ingestion),
            'visible_cliente' => $this->vaDirigidoAlCliente($ingestion, $process),
        ]);

        $this->colgarAdjuntos($process, $ingestion, $comment);

        return $comment;
    }

    /**
     * Para correos ya procesados con el formato anterior: rehace su nota
     * interna como mensaje del despacho y le cuelga los adjuntos.
     */
    public function convertir(Process $process, EmailIngestion $ingestion): Comment
    {
        $comment = $process->comments()->where('email_ingestion_id', $ingestion->id)->first();

        if (! $comment) {
            return $this->registrar($process, $ingestion);
        }

        $comment->forceFill([
            'body' => $this->cuerpo($ingestion),
            'visible_cliente' => $comment->visible_cliente || $this->vaDirigidoAlCliente($ingestion, $process),
        ])->save();

        $this->colgarAdjuntos($process, $ingestion, $comment);

        return $comment;
    }

    /** @return list<array{filename: string, mime_type: ?string, size: ?int}> */
    public function adjuntosReales(EmailIngestion $ingestion): array
    {
        return collect($ingestion->raw_payload['attachments'] ?? [])
            ->filter(fn ($a) => ! empty($a['filename']))
            ->reject(fn ($a) => str_starts_with((string) ($a['mime_type'] ?? ''), 'image/')
                && (int) ($a['size'] ?? 0) < self::IMAGEN_MINIMA_BYTES)
            ->values()
            ->all();
    }

    /** «📧 Respuesta enviada a …» como las respuestas que salen desde la plataforma. */
    public function cuerpo(EmailIngestion $ingestion): string
    {
        $para = collect(preg_split('/[,;]/', (string) $ingestion->to))->map(fn ($d) => self::soloCorreo($d))->filter()->implode(', ');
        $texto = self::sinCitas((string) $ingestion->body_text, (string) $ingestion->subject);

        return "📧 Respuesta enviada a {$para}\nAsunto: ".trim((string) $ingestion->subject)."\n\n".$texto;
    }

    /**
     * Quita del cuerpo lo citado del hilo («El lun… escribió:», «On … wrote:»)
     * y las líneas «> …». En un reenvío lo reenviado ES el contenido: se deja.
     */
    public static function sinCitas(string $texto, string $asunto = ''): string
    {
        $texto = str_replace("\r\n", "\n", $texto);

        if (! preg_match('/^\s*(fw|fwd|rv)\s*:/i', $asunto)) {
            $lineas = [];
            foreach (explode("\n", $texto) as $linea) {
                if (preg_match('/^\s*(El\s.+escribi[óo]:|On\s.+wrote:)\s*$/u', $linea)
                    || preg_match('/^\s*-{2,}\s*(Mensaje original|Original Message)/i', $linea)) {
                    break;
                }
                if (str_starts_with(ltrim($linea), '>')) {
                    continue;
                }
                $lineas[] = $linea;
            }
            $texto = implode("\n", $lineas);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $texto));
    }

    private function colgarAdjuntos(Process $process, EmailIngestion $ingestion, Comment $comment): void
    {
        foreach ($this->adjuntosReales($ingestion) as $a) {
            $doc = Document::firstOrNew([
                'email_ingestion_id' => $ingestion->id,
                'ruta' => "inbound/{$ingestion->message_id}/{$a['filename']}",
            ]);
            $doc->forceFill([
                'process_id' => $process->id,
                'client_id' => $process->client_id,
                'comment_id' => $comment->id,
                'nombre' => $a['filename'],
                'disco' => 'local',
                'tipo' => 'comunicacion',
                'mime' => $a['mime_type'] ?? $doc->mime,
                'tamano_bytes' => $a['size'] ?? $doc->tamano_bytes,
                'generado_por_ia' => false,
                // Van con el mensaje: compartir uno comparte los otros.
                'visible_cliente' => $comment->visible_cliente,
            ])->save();
        }
    }

    private function autor(EmailIngestion $ingestion, Process $process): ?int
    {
        return User::query()->whereRaw('LOWER(email) = ?', [self::soloCorreo($ingestion->from)])->value('id')
            ?? IntegrationToken::query()->where('provider', IntegrationToken::PROVIDER_GMAIL)->value('connected_by_user_id')
            ?? $process->abogado_lider_id
            ?? User::query()->value('id');
    }

    private function correosEquipo(): Collection
    {
        return $this->correosEquipo ??= User::query()->pluck('email')
            ->merge(IntegrationToken::query()->pluck('account_email'))
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->filter()
            ->unique();
    }

    /** El dominio propio del despacho, sacado de las cuentas de Gmail conectadas. */
    private function dominios(): Collection
    {
        return $this->dominios ??= IntegrationToken::query()->pluck('account_email')
            ->map(fn ($e) => mb_strtolower(substr(strrchr((string) $e, '@') ?: '', 1)))
            ->filter(fn ($d) => $d !== '' && ! in_array($d, self::PROVEEDORES_PUBLICOS, true))
            ->unique();
    }

    /** "Nombre <correo@x>" → "correo@x". */
    public static function soloCorreo(?string $valor): string
    {
        $valor = (string) $valor;

        return mb_strtolower(trim(preg_match('/<([^>]+)>/', $valor, $m) ? $m[1] : $valor));
    }
}
