<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Process;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Convierte un mensaje del despacho (respuesta de correo, borrador IA) en un
 * .docx con el logo: el cliente pedia «el documento» y no el texto suelto.
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
     * @return array{nombre: string, contenido: string}
     */
    public function generar(Comment $comment, Process $process): array
    {
        [$asunto, $cuerpo] = $this->separar((string) $comment->body);

        $fecha = Carbon::parse($comment->created_at)->locale('es')->translatedFormat('j \d\e F \d\e Y');

        $parrafos = [];
        $parrafos[] = $this->parrafo($fecha, ['alinear' => 'right', 'despues' => 240]);
        $parrafos[] = $this->parrafo('Señores', ['despues' => 0]);
        $parrafos[] = $this->parrafo((string) $process->client?->razon_social, ['negrita' => true, 'despues' => 0]);
        $parrafos[] = $this->parrafo('Ciudad', ['despues' => 240]);
        $parrafos[] = $this->parrafo('Asunto: '.($asunto ?? 'Comunicación del despacho'), ['negrita' => true, 'despues' => 0]);
        $parrafos[] = $this->parrafo("Proceso: {$process->codigo} — {$process->titulo}", ['despues' => 360]);

        foreach (preg_split('/\R/u', $cuerpo) as $linea) {
            $parrafos[] = $this->parrafo($this->sinMarkdown($linea), ['despues' => 0, 'interlineado' => 300]);
        }

        $nombre = Str::of($asunto ?? 'Comunicación')
            ->replaceMatches('/[\\\\\/:*?"<>|]+/u', ' ')
            ->squish()
            ->limit(80, '')
            ->append(" - {$process->codigo}.docx")
            ->toString();

        return ['nombre' => $nombre, 'contenido' => $this->empaquetar(implode('', $parrafos))];
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

    private function sinMarkdown(string $linea): string
    {
        return (string) preg_replace(['/^#{1,6}\s*/u', '/\*\*(.+?)\*\*/u', '/^\s*[-*]\s+/u'], ['', '$1', '• '], $linea);
    }

    /**
     * @param  array{alinear?: string, negrita?: bool, despues?: int, interlineado?: int}  $o
     */
    private function parrafo(string $texto, array $o = []): string
    {
        $pPr = '<w:spacing w:after="'.($o['despues'] ?? 120).'" w:line="'.($o['interlineado'] ?? 264).'" w:lineRule="auto"/>';
        if (isset($o['alinear'])) {
            $pPr .= '<w:jc w:val="'.$o['alinear'].'"/>';
        }

        $rPr = ! empty($o['negrita']) ? '<w:rPr><w:b/></w:rPr>' : '';
        $run = $texto === '' ? '' : '<w:r>'.$rPr.'<w:t xml:space="preserve">'.$this->xml($texto).'</w:t></w:r>';

        return '<w:p><w:pPr>'.$pPr.'</w:pPr>'.$run.'</w:p>';
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
            .($conLogo ? $this->logo() : $this->parrafo(self::DESPACHO, ['negrita' => true, 'despues' => 240]))
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
