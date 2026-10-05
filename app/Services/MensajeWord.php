<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Document;
use App\Models\Process;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Convierte en .docx con el logo lo que el despacho comparte como texto: los
 * mensajes (respuestas de correo, borradores IA) y los borradores IA
 * guardados como documento HTML. El cliente pedia «el documento»: abrir el
 * HTML le enseñaba el texto suelto, con los # del markdown, en el navegador.
 *
 * Se arma el paquete OOXML a mano con ZipArchive (ya se usa para leer .docx
 * en DocumentTextExtractor), sin anadir PhpWord por un documento tan simple.
 */
class MensajeWord
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const LOGO = 'images/logofondoblanco.png';

    private const DESPACHO = 'Protección Laboral Soluciones Legales';

    /**
     * Un mensaje del proceso. La respuesta de correo sale como carta (fecha,
     * destinatario, asunto); un borrador o nota sale tal cual, que ya trae
     * su propia forma.
     *
     * @return array{nombre: string, contenido: string}
     */
    public function generar(Comment $comment, Process $process): array
    {
        [$asunto, $cuerpo] = $this->separar((string) $comment->body);

        $xml = $asunto !== null || $this->esRespuesta((string) $comment->body)
            ? $this->carta($asunto, $process, Carbon::parse($comment->created_at))
            : '';
        $xml .= $this->cuerpo($cuerpo);

        return [
            'nombre' => $this->nombreArchivo($asunto ?? 'Comunicación', $process->codigo),
            'contenido' => $this->empaquetar($xml),
        ];
    }

    /**
     * Un borrador IA guardado como documento HTML (AiGenerationController::wrapAsHtml).
     *
     * @return array{nombre: string, contenido: string}
     */
    public function desdeHtml(Document $document): array
    {
        $disk = Storage::disk($document->disco ?? 'local');
        abort_unless($document->ruta && $disk->exists($document->ruta), 404, 'El archivo ya no está disponible.');

        return [
            'nombre' => $this->nombreArchivo((string) $document->nombre, null),
            'contenido' => $this->empaquetar($this->cuerpo($this->textoDeHtml($disk->get($document->ruta)))),
        ];
    }

    public static function esHtml(Document $document): bool
    {
        return str_starts_with((string) $document->mime, 'text/html');
    }

    private function esRespuesta(string $body): bool
    {
        return str_starts_with(ltrim($body), '📧');
    }

    /**
     * Las respuestas de correo se guardan como
     * «📧 Respuesta enviada a X\nAsunto: Y\n\n<cuerpo>»: el asunto va aparte y
     * la linea del destinatario sobra en una carta.
     *
     * @return array{0: ?string, 1: string}
     */
    private function separar(string $body): array
    {
        $body = str_replace("\r\n", "\n", $body);

        if (preg_match('/\A📧[^\n]*\nAsunto:[ \t]*([^\n]*)\n\n?(.*)\z/su', $body, $m)) {
            return [trim($m[1]) ?: null, rtrim($m[2])];
        }

        return [null, trim($body)];
    }

    /** Solo el <body> (el <title> repetia el nombre) y sin los <br> de nl2br. */
    private function textoDeHtml(string $html): string
    {
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }
        $html = preg_replace('/<br\s*\/?>\s*\n?/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|h[1-6]|li)>/i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function carta(?string $asunto, Process $process, Carbon $fecha): string
    {
        return $this->parrafo([[$fecha->locale('es')->translatedFormat('j \d\e F \d\e Y'), false]], ['alinear' => 'right', 'despues' => 240])
            .$this->parrafo([['Señores', false]], ['despues' => 0])
            .$this->parrafo([[(string) $process->client?->razon_social, true]], ['despues' => 0])
            .$this->parrafo([['Ciudad', false]], ['despues' => 240])
            .$this->parrafo([['Asunto: '.($asunto ?? 'Comunicación del despacho'), true]], ['despues' => 0])
            .$this->parrafo([["Proceso: {$process->codigo} — {$process->titulo}", false]], ['despues' => 360]);
    }

    /**
     * El texto, con el markdown que escribe la IA convertido en formato de
     * Word: «# Titulo» en negrita y mas grande, «**algo**» en negrita,
     * «- item» como viñeta, «---» como espacio.
     */
    private function cuerpo(string $texto): string
    {
        $xml = '';
        foreach (preg_split('/\R/u', $texto) as $linea) {
            if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $linea)) {
                $xml .= $this->parrafo([], ['despues' => 120]);
            } elseif (preg_match('/^\s*(#{1,6})\s+(.*)$/u', $linea, $h)) {
                $xml .= $this->parrafo([[str_replace('**', '', $h[2]), true]], [
                    'despues' => 120, 'antes' => 200, 'tamano' => strlen($h[1]) <= 2 ? 26 : 24,
                ]);
            } elseif (preg_match('/^\s*[-*•]\s+(.*)$/u', $linea, $b)) {
                $xml .= $this->parrafo($this->negritas('•  '.$b[1]), ['despues' => 60, 'sangria' => 360]);
            } else {
                $xml .= $this->parrafo($this->negritas($linea), ['despues' => 0, 'interlineado' => 300]);
            }
        }

        return $xml;
    }

    /** @return list<array{0: string, 1: bool}> trozos [texto, negrita] */
    private function negritas(string $linea): array
    {
        $trozos = [];
        foreach (preg_split('/(\*\*.+?\*\*)/u', $linea, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $t) {
            $trozos[] = str_starts_with($t, '**') && str_ends_with($t, '**') && strlen($t) > 4
                ? [substr($t, 2, -2), true]
                : [$t, false];
        }

        return $trozos;
    }

    private function nombreArchivo(string $base, ?string $codigo): string
    {
        return Str::of($base)
            ->replaceMatches('/\.(html?|docx?)$/i', '')
            ->replaceMatches('/[\\\\\/:*?"<>|]+/u', ' ')
            ->squish()
            ->limit(90, '')
            ->append($codigo ? " - {$codigo}.docx" : '.docx')
            ->toString();
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $trozos
     * @param  array{alinear?: string, despues?: int, antes?: int, interlineado?: int, tamano?: int, sangria?: int}  $o
     */
    private function parrafo(array $trozos, array $o = []): string
    {
        $pPr = '<w:spacing w:before="'.($o['antes'] ?? 0).'" w:after="'.($o['despues'] ?? 120).'" w:line="'.($o['interlineado'] ?? 264).'" w:lineRule="auto"/>';
        if (isset($o['sangria'])) {
            $pPr .= '<w:ind w:left="'.$o['sangria'].'"/>';
        }
        if (isset($o['alinear'])) {
            $pPr .= '<w:jc w:val="'.$o['alinear'].'"/>';
        }

        $runs = '';
        foreach ($trozos as [$texto, $negrita]) {
            if ($texto === '') {
                continue;
            }
            $rPr = ($negrita ? '<w:b/>' : '').(isset($o['tamano']) ? '<w:sz w:val="'.$o['tamano'].'"/><w:szCs w:val="'.$o['tamano'].'"/>' : '');
            $runs .= '<w:r>'.($rPr ? "<w:rPr>{$rPr}</w:rPr>" : '').'<w:t xml:space="preserve">'.$this->xml($texto).'</w:t></w:r>';
        }

        return '<w:p><w:pPr>'.$pPr.'</w:pPr>'.$runs.'</w:p>';
    }

    private function logo(): string
    {
        // 4 cm de ancho, respetando la proporcion del PNG (356×252).
        $cx = 1440000;
        $cy = (int) round($cx * 252 / 356);

        return '<w:p><w:pPr><w:spacing w:after="240"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="1" name="Logo"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:nvPicPr><pic:cNvPr id="1" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private function empaquetar(string $cuerpo): string
    {
        $conLogo = is_file(public_path(self::LOGO));

        $documento = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<w:body>'
            .($conLogo ? $this->logo() : $this->parrafo([[self::DESPACHO, true]], ['despues' => 240]))
            .$cuerpo
            .'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            .'<w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1418" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr>'
            .'</w:body></w:document>';

        $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
            .'<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="es-CO"/></w:rPr></w:rPrDefault></w:docDefaults>'
            .'</w:styles>';

        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .($conLogo ? '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>' : '')
            .'</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'msgdocx');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el documento Word.');
        }
        $zip->addFromString('[Content_Types].xml', $tipos);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $documento);
        $zip->addFromString('word/styles.xml', $estilos);
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);
        if ($conLogo) {
            $zip->addFile(public_path(self::LOGO), 'word/media/logo.png');
        }
        $zip->close();

        $contenido = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $contenido;
    }

    private function xml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
