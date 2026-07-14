<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Extrae texto plano de un documento subido (docx, pdf, txt/md) para que la IA
 * pueda interpretarlo. El docx se lee directamente del XML (capta tablas, donde
 * suelen vivir las etapas del plan de trabajo); el pdf usa Smalot\PdfParser.
 */
class DocumentTextExtractor
{
    public const SUPPORTED = ['pdf', 'docx', 'txt', 'md'];

    public function extract(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return $this->normalize($this->fromPath($file->getRealPath(), $ext));
    }

    /**
     * Extrae (y cachea) el texto de un Document ya guardado en disco, para que la IA
     * pueda usar su CONTENIDO como contexto. Es tolerante: nunca lanza — devuelve null
     * si el formato no se soporta, el archivo no existe o falla la extracción.
     *
     * Cachea el resultado en `documents.texto_extraido` (+ `texto_extraido_at` marca el
     * intento) para no re-extraer en cada generación. Vuelve a intentar solo si el archivo
     * cambió (`updated_at` posterior al último intento).
     */
    public function extractFromDocument(Document $doc): ?string
    {
        $yaIntentado = $doc->texto_extraido_at !== null
            && (! $doc->updated_at || $doc->texto_extraido_at->greaterThanOrEqualTo($doc->updated_at));

        if ($yaIntentado) {
            return ($doc->texto_extraido ?? '') !== '' ? $doc->texto_extraido : null;
        }

        $texto = null;
        try {
            $ext = strtolower(pathinfo((string) $doc->ruta, PATHINFO_EXTENSION));
            if (in_array($ext, self::SUPPORTED, true)) {
                $disk = Storage::disk($doc->disco ?: 'local');
                if ($doc->ruta && $disk->exists($doc->ruta)) {
                    $texto = $this->normalize($this->fromPath($disk->path($doc->ruta), $ext));
                }
            }
        } catch (Throwable $e) {
            report($e);
            $texto = null;
        }

        // Marca el intento aunque no se haya podido leer (cadena vacía), para no reintentar
        // en cada generación. forceFill: no dispara eventos ni toca `updated_at` manualmente.
        $doc->forceFill([
            'texto_extraido' => $texto ?? '',
            'texto_extraido_at' => now(),
        ])->saveQuietly();

        return ($texto !== null && $texto !== '') ? $texto : null;
    }

    private function fromPath(string $path, string $ext): string
    {
        return match ($ext) {
            'pdf' => $this->fromPdf($path),
            'docx' => $this->fromDocx($path),
            'txt', 'md' => (string) file_get_contents($path),
            default => throw new RuntimeException("Formato no soportado: .{$ext}. Usa PDF, DOCX o TXT."),
        };
    }

    private function fromPdf(string $path): string
    {
        return (new PdfParser())->parseFile($path)->getText();
    }

    /**
     * Lee word/document.xml del .docx y lo convierte a texto, preservando los
     * saltos de párrafo y los límites de celda/fila de las tablas.
     */
    private function fromDocx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo .docx.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('El .docx no contiene word/document.xml (¿archivo corrupto?).');
        }

        // Saltos de párrafo y separadores de tabla → saltos de línea / tabs antes de limpiar el XML.
        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
        $xml = preg_replace('/<\/w:tr>/', "\n", $xml);
        $xml = preg_replace('/<\/w:tc>/', "\t", $xml);
        $xml = preg_replace('/<w:tab\b[^>]*\/>/', "\t", $xml);
        $xml = preg_replace('/<w:br\b[^>]*\/>/', "\n", $xml);

        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Colapsa espacios redundantes y líneas en blanco repetidas, recortando el
     * resultado a un tamaño razonable para el prompt.
     */
    private function normalize(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Límite defensivo: ~60k caracteres es suficiente para planes/contratos.
        return mb_substr($text, 0, 60000);
    }
}
