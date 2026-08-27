<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lee escaneados y fotos que no tienen texto, usando la vista del propio modelo.
 *
 * En un despacho laboral lo escaneado es justo lo que importa —demandas
 * radicadas, sentencias, incapacidades, dictámenes— y hasta ahora era invisible
 * para la IA: de 411 documentos, 70 eran imágenes sin una sola letra extraída.
 * `DocumentTextExtractor` no las toca porque no hay texto que sacar de un PNG.
 *
 * No se añade Tesseract ni Google Vision como dependencia nueva: la clave de
 * Anthropic ya está configurada y el modelo lee escaneos en español mejor que
 * un OCR clásico, además de entender la estructura del documento.
 *
 * Los bytes salen de donde estén: del disco si el documento es local, y de
 * Drive si quedó guardado como enlace (`disco='gdrive'`, que es lo que hace la
 * sincronización con `DRIVE_STORE_FILES=false`).
 */
class DocumentOcr
{
    /** Extensiones que sabemos mandar como imagen a la API. */
    public const SOPORTADAS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    /**
     * Techo del archivo, en bytes.
     *
     * La API rechaza imágenes grandes, y en base64 el peso crece un tercio.
     * Cinco megas de origen es un escaneo holgado; por encima conviene fallar
     * limpio y dejarlo anotado antes que gastar una llamada que va a rebotar.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Techo del texto que se guarda, alineado con el resto del pipeline. */
    public const MAX_TEXTO = 12000;

    public function __construct(
        private readonly AiService $ai,
        private readonly DriveService $drive,
    ) {}

    /**
     * Nombres que Outlook le pone a las imágenes INCRUSTADAS en el cuerpo de un
     * correo — es decir, a los logos de la firma.
     *
     * Un adjunto de verdad conserva su nombre original («incapacidad juan.jpg»),
     * así que esto no se lleva por delante un escaneado que llegue por correo.
     */
    public const NOMBRES_INCRUSTADOS = ['Outlook-', 'image00', 'image.png'];

    /**
     * ¿Esto es el logo de una firma de correo y no un documento?
     *
     * Importa más de lo que parece: el texto extraído alimenta el resumen del
     * documento, que alimenta la ficha del cliente. Leer un logo mete
     * «ZONAMEDICA IPS · Salud de verdad! · ISO 9001 · BUREAU VERITAS» en el
     * conocimiento del cliente. Eso es peor que dejar el documento vacío,
     * porque ensucia el contexto de todos los borradores que se generen.
     *
     * Medido sobre los 68 pendientes: las 38 que cumplen las dos condiciones
     * eran logos, y las 30 restantes eran las páginas escaneadas de una
     * escritura pública. Se piden LAS DOS —origen de correo y nombre de imagen
     * incrustada— porque cada una por separado se equivocaría.
     */
    public function pareceFirmaDeCorreo(Document $doc): bool
    {
        if ($doc->email_ingestion_id === null) {
            return false;
        }

        return Str::contains((string) $doc->nombre, self::NOMBRES_INCRUSTADOS);
    }

    /** ¿Este documento es candidato a OCR? */
    public function puedeLeer(Document $doc): bool
    {
        if (filled($doc->texto_extraido)) {
            return false;   // ya tiene texto por otra vía
        }

        $ext = strtolower(pathinfo((string) $doc->nombre, PATHINFO_EXTENSION));

        return in_array($ext, self::SOPORTADAS, true);
    }

    /**
     * Extrae el texto de la imagen y lo guarda como `texto_extraido`.
     *
     * Se guarda ahí y no en un campo aparte a propósito: para el resto del
     * sistema —la ficha, el resumen, el contexto del proceso— un escaneado
     * leído es un documento con texto como cualquier otro, y así todo lo que ya
     * existe lo aprovecha sin cambiar una línea.
     *
     * Nunca lanza: un escaneado ilegible no puede tumbar el lote.
     */
    public function read(Document $doc, bool $forzar = false): ?string
    {
        if (! $forzar && ! $this->puedeLeer($doc)) {
            return null;
        }

        $bytes = $this->bytes($doc);
        if ($bytes === null) {
            return null;
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $this->log($doc, null, 'error', new \RuntimeException(
                'Imagen de '.number_format(strlen($bytes) / 1048576, 1).' MB: supera el techo de OCR.'
            ));

            return null;
        }

        try {
            $response = $this->ai->generateDraft($this->prompt($doc), null, [
                'temperature' => 0.0,
                'max_tokens' => 4000,
                'timeout' => 120,
                'images' => [[
                    'media_type' => $this->mediaType($doc),
                    'data' => base64_encode($bytes),
                ]],
            ]);

            $texto = trim((string) $response['text']);

            // El modelo devuelve esta marca cuando la imagen no tiene texto
            // legible. Se distingue de un fallo: el documento queda marcado como
            // intentado para no volver a pagar por él en cada corrida.
            if ($texto === '' || Str::startsWith($texto, '[SIN TEXTO')) {
                $texto = '';
            }

            $doc->forceFill([
                'texto_extraido' => Str::limit($texto, self::MAX_TEXTO, '… [recortado]'),
                'texto_extraido_at' => now(),
            ])->saveQuietly();

            $this->log($doc, $response, 'ok');

            return $texto !== '' ? $texto : null;
        } catch (Throwable $e) {
            report($e);
            $this->log($doc, null, 'error', $e);

            return null;
        }
    }

    /** Los bytes del archivo, esté en disco o en Drive. */
    protected function bytes(Document $doc): ?string
    {
        try {
            if ($doc->disco === 'gdrive') {
                if (blank($doc->drive_file_id)) {
                    return null;
                }

                return $this->drive->downloadFile([
                    'id' => $doc->drive_file_id,
                    'name' => $doc->nombre,
                    'mime_type' => $doc->mime ?: $this->mediaType($doc),
                ]);
            }

            $disk = Storage::disk($doc->disco ?: 'local');

            return $disk->exists($doc->ruta) ? $disk->get($doc->ruta) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function mediaType(Document $doc): string
    {
        $ext = strtolower(pathinfo((string) $doc->nombre, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    protected function prompt(Document $doc): string
    {
        return <<<PROMPT
        Transcribe el texto de esta imagen. Es un documento de un despacho de
        derecho laboral colombiano, llamado «{$doc->nombre}».

        Reglas:
        - Devuelve ÚNICAMENTE el texto que se ve, sin comentarlo ni resumirlo.
        - Respeta el orden de lectura y separa los párrafos con saltos de línea.
        - Conserva tal cual los números: cédulas, NIT, radicados, fechas y cifras.
        - Si una palabra no se lee con certeza, escríbela seguida de [?]. NO la adivines.
        - Si la imagen no contiene texto legible (es una foto, un logo o está en
          blanco), responde exactamente: [SIN TEXTO LEGIBLE]
        PROMPT;
    }

    protected function log(Document $doc, ?array $response, string $estado, ?Throwable $e = null): void
    {
        $cost = 0.0;
        if ($response) {
            try {
                $cost = $this->ai->estimateCost(
                    $response['usage']['input_tokens'] ?? 0,
                    $response['usage']['output_tokens'] ?? 0,
                    $response['model'] ?? null,
                );
            } catch (Throwable) {
                $cost = 0.0;
            }
        }

        AiGeneration::create([
            'user_id' => null,
            'contexto_tipo' => Document::class,
            'contexto_id' => $doc->id,
            'proveedor' => 'anthropic',
            'modelo' => $response['model'] ?? config('anthropic.model'),
            'request_hash' => $response['request_hash'] ?? null,
            'prompt' => 'document_ocr: '.Str::limit((string) $doc->nombre, 120),
            'respuesta' => Str::limit($response['text'] ?? '', 2000),
            'tokens_in' => $response['usage']['input_tokens'] ?? 0,
            'tokens_out' => $response['usage']['output_tokens'] ?? 0,
            'latencia_ms' => $response['latencia_ms'] ?? 0,
            'costo_usd' => $cost,
            'estado' => $estado,
            'error_mensaje' => $e ? Str::limit($e->getMessage(), 500) : null,
        ]);
    }
}
