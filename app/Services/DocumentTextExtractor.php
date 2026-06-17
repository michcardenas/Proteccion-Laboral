<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
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
        $path = $file->getRealPath();

        $text = match ($ext) {
            'pdf' => $this->fromPdf($path),
            'docx' => $this->fromDocx($path),
            'txt', 'md' => (string) file_get_contents($path),
            default => throw new RuntimeException("Formato no soportado: .{$ext}. Usa PDF, DOCX o TXT."),
        };

        return $this->normalize($text);
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
