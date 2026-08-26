<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Acceso de solo lectura a la unidad compartida de Google Drive del despacho.
 *
 * Reusa el token OAuth de la cuenta ya conectada por Gmail (GmailService gestiona el
 * refresco), así que NO hay un segundo flujo de consentimiento: basta con que el scope
 * `drive.readonly` esté en config/gmail.php y que la cuenta se haya reconectado después.
 *
 * OJO con las unidades compartidas: la API solo las devuelve si se pasan
 * `supportsAllDrives`, `includeItemsFromAllDrives` y `corpora=drive` + `driveId`.
 * Sin esos parámetros el listado vuelve VACÍO sin error, que es el fallo típico.
 */
class DriveService
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /**
     * Archivos nativos de Google Workspace: no se descargan, se EXPORTAN.
     * mime nativo => [mime de exportación, extensión con la que se guarda].
     */
    public const EXPORTABLE = [
        'application/vnd.google-apps.document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
        'application/vnd.google-apps.spreadsheet' => ['text/csv', 'csv'],
        'application/vnd.google-apps.presentation' => ['text/plain', 'txt'],
    ];

    protected ?GoogleClient $client = null;

    protected ?Drive $drive = null;

    public function __construct(protected GmailService $gmail)
    {
    }

    /**
     * Inyecta un Google_Client ya autorizado (p. ej. un mock en tests).
     */
    public function setClient(GoogleClient $client): static
    {
        $this->client = $client;
        $this->drive = null;

        return $this;
    }

    /**
     * Inyecta un servicio Drive ya construido (tests).
     */
    public function setDrive(Drive $drive): static
    {
        $this->drive = $drive;

        return $this;
    }

    public function drive(): Drive
    {
        return $this->drive ??= new Drive($this->client ?? $this->gmail->authorizedClient());
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     * Unidades compartidas visibles para la cuenta conectada.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function listSharedDrives(): array
    {
        $out = [];
        foreach ($this->drive()->drives->listDrives(['pageSize' => 100])->getDrives() ?? [] as $d) {
            $out[] = ['id' => $d->getId(), 'name' => $d->getName()];
        }

        return $out;
    }

    /**
     * Id de la unidad compartida a usar: la configurada o, si no hay, la primera visible.
     *
     * @throws RuntimeException si la cuenta no ve ninguna unidad compartida.
     */
    /**
     * Id de la unidad compartida, o null si el contenido no vive en una.
     *
     * Al despacho no le dieron una unidad compartida: le COMPARTIERON una
     * carpeta normal, que aparece en «Compartido conmigo» y no tiene driveId.
     * Eso funciona igual —`listChildren()` solo manda `corpora=drive` cuando
     * hay driveId—, pero antes esto reventaba con «no ve ninguna unidad
     * compartida» y dejaba la integración muerta aunque los archivos sí se
     * alcanzaran. Con `DRIVE_ROOT_FOLDER_ID` configurado ya no hace falta.
     */
    public function resolveSharedDriveId(): ?string
    {
        if ($configurado = config('drive.shared_drive_id')) {
            return $configurado;
        }

        $drives = $this->listSharedDrives();
        if ($drives !== []) {
            return $drives[0]['id'];
        }

        if (config('drive.root_folder_id')) {
            return null;
        }

        throw new RuntimeException(
            'La cuenta conectada no ve ninguna unidad compartida y no hay DRIVE_ROOT_FOLDER_ID configurado. '
            .'Si en vez de una unidad les compartieron una carpeta, pon su id en DRIVE_ROOT_FOLDER_ID. '
            .'Si esperabas una unidad, verifica que la cuenta sea miembro y que el token incluya drive.readonly.'
        );
    }

    /**
     * Hijos directos de una carpeta (archivos y subcarpetas), normalizados.
     *
     * @return array<int, array>
     */
    public function listChildren(string $parentId, ?string $driveId = null): array
    {
        $params = [
            'q' => "'".addslashes($parentId)."' in parents and trashed = false",
            'fields' => 'nextPageToken, files(id,name,mimeType,modifiedTime,size,webViewLink)',
            'pageSize' => 200,
            'orderBy' => 'folder,name',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ];

        if ($driveId) {
            $params['corpora'] = 'drive';
            $params['driveId'] = $driveId;
        }

        $items = [];
        $pageToken = null;

        do {
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->drive()->files->listFiles($params);

            foreach ($response->getFiles() ?? [] as $file) {
                $items[] = $this->normalizeFile([
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                    'modifiedTime' => $file->getModifiedTime(),
                    'size' => $file->getSize(),
                    'webViewLink' => $file->getWebViewLink(),
                ]);
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $items;
    }

    /**
     * Solo las subcarpetas directas de una carpeta.
     *
     * @return array<int, array>
     */
    public function listFolders(string $parentId, ?string $driveId = null): array
    {
        return array_values(array_filter(
            $this->listChildren($parentId, $driveId),
            fn (array $item) => $item['is_folder']
        ));
    }

    /**
     * Recorre una carpeta y sus subcarpetas devolviendo solo ARCHIVOS, con la ruta
     * relativa acumulada en `path` para conservar el contexto de la subcarpeta.
     *
     * @return array<int, array>
     */
    public function listFilesRecursive(string $folderId, ?string $driveId = null, ?int $maxDepth = null, string $prefix = ''): array
    {
        $maxDepth ??= (int) config('drive.max_depth', 5);

        $archivos = [];

        foreach ($this->listChildren($folderId, $driveId) as $item) {
            if ($item['is_folder']) {
                if ($maxDepth <= 1) {
                    continue;
                }

                $archivos = array_merge($archivos, $this->listFilesRecursive(
                    $item['id'],
                    $driveId,
                    $maxDepth - 1,
                    $prefix === '' ? $item['name'] : $prefix.'/'.$item['name'],
                ));

                continue;
            }

            $item['path'] = $prefix;
            $archivos[] = $item;
        }

        return $archivos;
    }

    // ------------------------------------------------------------------
    // Descarga
    // ------------------------------------------------------------------

    /**
     * Descarga (o exporta, si es un archivo nativo de Google) el contenido binario.
     * Devuelve null si el archivo no es descargable o si la API falla.
     */
    public function downloadFile(array $file): ?string
    {
        try {
            if ($export = $this->exportFor($file['mime_type'])) {
                $response = $this->drive()->files->export($file['id'], $export[0], ['alt' => 'media']);
            } else {
                $response = $this->drive()->files->get($file['id'], [
                    'alt' => 'media',
                    'supportsAllDrives' => true,
                ]);
            }

            return (string) $response->getBody()->getContents();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    // ------------------------------------------------------------------
    // Helpers puros (testeables sin tocar la red)
    // ------------------------------------------------------------------

    /**
     * Normaliza la respuesta REST de un archivo al vocabulario del dominio.
     *
     * @return array{id: string, name: string, mime_type: string, modified_at: ?Carbon, size: ?int, web_view_link: ?string, is_folder: bool, path: string}
     */
    public function normalizeFile(array $raw): array
    {
        return [
            'id' => (string) ($raw['id'] ?? ''),
            'name' => (string) ($raw['name'] ?? 'sin-nombre'),
            'mime_type' => (string) ($raw['mimeType'] ?? ''),
            'modified_at' => ! empty($raw['modifiedTime']) ? Carbon::parse($raw['modifiedTime']) : null,
            'size' => isset($raw['size']) ? (int) $raw['size'] : null,
            'web_view_link' => $raw['webViewLink'] ?? null,
            'is_folder' => ($raw['mimeType'] ?? '') === self::FOLDER_MIME,
            'path' => '',
        ];
    }

    /**
     * Devuelve [mime de exportación, extensión] si el archivo es un nativo de Google.
     *
     * @return array{0: string, 1: string}|null
     */
    public function exportFor(string $mimeType): ?array
    {
        return self::EXPORTABLE[$mimeType] ?? null;
    }

    /**
     * Extensión con la que se guardará el archivo: la de exportación para los nativos
     * de Google, la del propio nombre para el resto.
     */
    public function targetExtension(array $file): string
    {
        if ($export = $this->exportFor($file['mime_type'])) {
            return $export[1];
        }

        return strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    }

    /**
     * ¿La IA puede leer este archivo? (determina si vale la pena descargarlo).
     */
    public function esLegible(array $file): bool
    {
        return in_array($this->targetExtension($file), DocumentTextExtractor::SUPPORTED, true);
    }

    /**
     * ¿Supera el tope de tamaño configurado? Los nativos de Google no reportan `size`
     * y se consideran siempre dentro del límite.
     */
    public function excedeTamano(array $file): bool
    {
        $max = (int) config('drive.max_file_mb', 25) * 1024 * 1024;

        return $file['size'] !== null && $file['size'] > $max;
    }
}
