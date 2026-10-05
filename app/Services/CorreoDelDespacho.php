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
        if (! $this->esCorreoDelDespacho(self::soloCorreo($ingestion->from))) {
            return false;
        }

        // Reenviar a la bandeja el correo de un cliente (lo más común: 36 de
        // 40 reenvíos en prod) no lo convierte en mensaje del despacho: el
        // autor es el del correo reenviado, y el adjunto, suyo.
        if ($this->esReenvio($ingestion) && ($original = self::reenviado((string) $ingestion->body_text))) {
            return $this->esCorreoDelDespacho($original['de']);
        }

        return true;
    }

    private function esReenvio(EmailIngestion $ingestion): bool
    {
        return preg_match('/^\s*(fw|fwd|rv)\s*:/i', (string) $ingestion->subject)
            || preg_match('/^\s*-{3,}\s*(Forwarded message|Mensaje reenviado)/imu', (string) $ingestion->body_text);
    }

    private function esCorreoDelDespacho(string $correo): bool
    {
        if ($correo === '' || ! str_contains($correo, '@')) {
            return false;
        }

        return $this->correosEquipo()->contains($correo)
            || $this->dominios()->contains(substr(strrchr($correo, '@'), 1));
    }

    /**
     * Se comparte solo si el cliente está entre los destinatarios. Un reenvío
     * a la bandeja o un correo interno queda para el equipo, que lo comparte
     * con un clic (y con él sus adjuntos).
     */
    public function vaDirigidoAlCliente(EmailIngestion $ingestion, Process $process): bool
    {
        $destinatarios = collect(preg_split('/[,;]/', (string) $ingestion->to))->map(fn ($d) => self::soloCorreo($d))->filter();

        // Lo habitual: la abogada escribe al cliente y luego REENVÍA ese
        // correo a la bandeja. El cliente no está en «Para» del reenvío, pero
        // sí en el del correo reenviado.
        if ($original = $this->reenvioPropio($ingestion)) {
            $destinatarios = $destinatarios->merge($original['para']);
        }

        return $destinatarios->intersect($process->client?->correos() ?? collect())->isNotEmpty();
    }

    /**
     * Si el correo es el reenvío de uno que escribió el propio despacho,
     * devuelve ese correo original; si no (reenvío de un correo del cliente,
     * o no es reenvío), null.
     *
     * @return array{de: string, para: list<string>, asunto: ?string, cuerpo: string}|null
     */
    public function reenvioPropio(EmailIngestion $ingestion): ?array
    {
        // Solo reenvíos: una respuesta normal también cita «De: / Para:» del
        // hilo y no por eso es el correo que hay que enseñar.
        if (! $this->esReenvio($ingestion)) {
            return null;
        }

        $original = self::reenviado((string) $ingestion->body_text);

        return $original && $this->esCorreoDelDespacho($original['de']) ? $original : null;
    }

    /**
     * Lee la cabecera del primer mensaje reenviado dentro del cuerpo. Gmail
     * («---------- Mensaje reenviado / Forwarded message ---------») y
     * Outlook («De: / Enviado: / Para: / Asunto:»), en español o inglés.
     *
     * @return array{de: string, para: list<string>, asunto: ?string, cuerpo: string}|null
     */
    public static function reenviado(string $texto): ?array
    {
        $lineas = explode("\n", str_replace("\r\n", "\n", $texto));
        $clave = '/^\s*\**\s*(De|From|Fecha|Date|Enviado(?: el)?|Sent|Asunto|Subject|Para|To|Cc|CC)\s*\**\s*:\s*\**\s*(.*)$/iu';

        // Dónde empieza la cabecera: tras el separador de Gmail o en la primera «De:/From:».
        $inicio = null;
        foreach ($lineas as $i => $linea) {
            if (preg_match('/^\s*-{3,}\s*(Forwarded message|Mensaje reenviado|Original Message|Mensaje original)\s*-{3,}\s*$/iu', $linea)) {
                $inicio = $i + 1;
                break;
            }
            if (preg_match('/^\s*\**\s*(De|From)\s*\**\s*:/iu', $linea)) {
                $inicio = $i;
                break;
            }
        }
        if ($inicio === null) {
            return null;
        }

        $campos = [];
        $i = $inicio;
        for (; $i < count($lineas); $i++) {
            if (trim($lineas[$i]) === '') {
                if ($campos) {
                    break;
                }

                continue;
            }
            if (! preg_match($clave, $lineas[$i], $m)) {
                break;
            }
            $campos[mb_strtolower(explode(' ', $m[1])[0])] = trim($m[2], " *\t");
        }

        $de = $campos['de'] ?? $campos['from'] ?? null;
        if (! $de) {
            return null;
        }

        $correos = fn (?string $v) => preg_match_all('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string) $v, $m) ? array_map('mb_strtolower', $m[0]) : [];

        return [
            'de' => $correos($de)[0] ?? '',
            'para' => array_values(array_unique(array_merge(
                $correos($campos['para'] ?? $campos['to'] ?? null),
                $correos($campos['cc'] ?? null),
            ))),
            'asunto' => $campos['asunto'] ?? $campos['subject'] ?? null,
            'cuerpo' => self::sinCitas(implode("\n", array_slice($lineas, $i))),
        ];
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

    /**
     * Deshace la conversión de un correo que resultó no ser del despacho (el
     * reenvío de un correo del cliente): vuelve la nota interna y el adjunto
     * como soporte suelto. No toca lo que alguien ya compartió.
     *
     * @return bool false si estaba compartido y se dejó como está
     */
    public function revertir(Comment $comment, EmailIngestion $ingestion): bool
    {
        if ($comment->visible_cliente) {
            return false;
        }

        $comment->forceFill(['body' => EmailRouter::notaEntrante($ingestion)])->save();
        Document::where('comment_id', $comment->id)->where('email_ingestion_id', $ingestion->id)
            ->update(['comment_id' => null, 'tipo' => 'soporte', 'visible_cliente' => false]);

        return true;
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
        // Reenvío de un correo propio: lo que vio el cliente es el original,
        // no la nota del reenvío ni la cabecera «De: / Para:».
        if ($original = $this->reenvioPropio($ingestion)) {
            $asunto = $original['asunto'] ?: preg_replace('/^\s*(fw|fwd|rv)\s*:\s*/i', '', (string) $ingestion->subject);

            return '📧 Respuesta enviada a '.implode(', ', $original['para'])."\nAsunto: ".trim($asunto)."\n\n".$original['cuerpo'];
        }

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
            $todas = explode("\n", $texto);
            foreach ($todas as $n => $linea) {
                if (preg_match('/^\s*(El\s.+escribi[óo]:|On\s.+wrote:)\s*$/u', $linea)
                    || preg_match('/^\s*-{2,}\s*(Mensaje original|Original Message|Forwarded message|Mensaje reenviado)/i', $linea)
                    // Cita de Outlook: «De: …» y debajo «Enviado:/Sent:/Fecha:/Date:».
                    || (preg_match('/^\s*\**\s*(De|From)\s*\**\s*:/iu', $linea)
                        && preg_match('/^\s*\**\s*(Enviado|Sent|Fecha|Date)/iu', $todas[$n + 1] ?? ''))) {
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
