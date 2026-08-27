<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\DriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Descubre las carpetas de la unidad compartida y las asocia a los clientes.
 *
 * Sin `--apply` no escribe nada: solo muestra la propuesta para revisarla. El emparejado
 * es por NIT dentro del nombre de la carpeta (señal fuerte) o por parecido con la razón
 * social (señal débil, siempre revisable a mano).
 */
class DriveMapClients extends Command
{
    protected $signature = 'drive:map-clients
        {--list-drives : Solo lista las unidades compartidas visibles y termina}
        {--drive= : Id de la unidad compartida (por defecto, la de config/drive.php)}
        {--folder= : Carpeta raíz donde viven las carpetas por cliente}
        {--threshold=0.6 : Confianza mínima para proponer un emparejado}
        {--nivel=2 : A qué profundidad viven las carpetas de empresa (1 = hijas de la raíz)}
        {--apply : Guarda los emparejados propuestos en los clientes}
        {--crear-faltantes : Crea un cliente para cada carpeta sin coincidencia (requiere --apply)}';

    protected $description = 'Mapea las carpetas de la unidad compartida de Drive con los clientes';

    public function handle(DriveService $drive): int
    {
        try {
            if ($this->option('list-drives')) {
                return $this->listarUnidades($drive);
            }

            $driveId = $this->option('drive') ?: $drive->resolveSharedDriveId();
            $rootId = $this->option('folder') ?: config('drive.root_folder_id') ?: $driveId;

            $this->line('Unidad compartida: <info>'.($driveId ?: 'ninguna (carpeta compartida)').'</info>');
            $this->line("Carpeta raíz: <info>{$rootId}</info>");

            $nivel = max(1, (int) $this->option('nivel'));
            $carpetas = $this->carpetasDeEmpresa($drive, $rootId, $driveId, $nivel);

            if ($nivel > 1) {
                $this->line("Profundidad: <info>{$nivel}</info> (las carpetas de empresa cuelgan de las de cada abogada)");
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->warn('Si el error menciona permisos o scopes, reconecta la cuenta en /admin/integrations/gmail.');

            return self::FAILURE;
        }

        if ($carpetas === []) {
            $this->warn('No se encontraron carpetas. Revisa que la cuenta conectada tenga acceso a la unidad y que el token incluya drive.readonly.');

            return self::FAILURE;
        }

        $clientes = Client::query()->whereNull('deleted_at')->get();
        $umbral = (float) $this->option('threshold');

        $filas = [];
        $aplicados = 0;
        $creados = 0;

        foreach ($carpetas as $carpeta) {
            $yaMapeado = $clientes->firstWhere('drive_folder_id', $carpeta['id']);

            if ($yaMapeado) {
                $filas[] = [$carpeta['ruta'] ?? '', $carpeta['name'], $yaMapeado->razon_social, '—', 'ya mapeada'];

                continue;
            }

            $sugerencia = $this->sugerirCliente($carpeta['name'], $clientes);

            if ($sugerencia === null || $sugerencia['score'] < $umbral) {
                // Sin cliente al que emparejar. Con --crear-faltantes se crea
                // uno a partir de la carpeta: en un despacho que lleva anos
                // trabajando en Drive, la lista de carpetas ES la lista de
                // clientes, y crearlos a mano son treinta y dos formularios.
                //
                // Se crea uno POR CARPETA aunque el nombre se repita entre
                // abogadas (SUMITOR esta bajo dos): fusionarlos automaticamente
                // dejaria una carpeta sin sincronizar y sus documentos
                // invisibles, que es peor que un duplicado a la vista.
                if ($this->option('crear-faltantes') && $this->option('apply')) {
                    $cliente = Client::create([
                        'razon_social' => $this->nombreDeCliente($carpeta['name']),
                        'estado' => 'activo',
                        'fecha_alta' => now()->toDateString(),
                        'notas' => 'Creado desde la carpeta de Drive «'.($carpeta['ruta'] ?? '').'/'.$carpeta['name'].'». Falta NIT y datos de contacto.',
                        'drive_folder_id' => $carpeta['id'],
                        'drive_folder_name' => trim(($carpeta['ruta'] ?? '').' / '.$carpeta['name']),
                    ]);
                    $clientes->push($cliente);
                    $creados++;

                    $filas[] = [$carpeta['ruta'] ?? '', $carpeta['name'], $cliente->razon_social, '—', 'CLIENTE CREADO'];

                    continue;
                }

                $filas[] = [$carpeta['ruta'] ?? '', $carpeta['name'], '—', '—', 'sin coincidencia'];

                continue;
            }

            /** @var Client $cliente */
            $cliente = $sugerencia['cliente'];
            $estado = 'propuesta ('.$sugerencia['motivo'].')';

            if ($this->option('apply')) {
                $cliente->forceFill([
                    'drive_folder_id' => $carpeta['id'],
                    'drive_folder_name' => $carpeta['name'],
                ])->save();
                $aplicados++;
                $estado = 'APLICADA ('.$sugerencia['motivo'].')';
            }

            $filas[] = [
                $carpeta['ruta'] ?? '',
                $carpeta['name'],
                $cliente->razon_social,
                number_format($sugerencia['score'], 2),
                $estado,
            ];
        }

        $this->newLine();
        $this->table(['Abogada', 'Carpeta en Drive', 'Cliente', 'Confianza', 'Estado'], $filas);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Nada se guardó. Repite con --apply para persistir los emparejados.');
            $this->line('Las carpetas sin coincidencia se mapean a mano: Client::find($id)->update([\'drive_folder_id\' => \'...\']).');
        } else {
            $this->info("{$aplicados} cliente(s) mapeado(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Carpetas de empresa, que no cuelgan de la raíz.
     *
     * El despacho organiza el Drive por ABOGADA y dentro de cada una van las
     * empresas: `ASESORIAS EMPRESAS / DRA CAROLINA / 3 ELIAS ACOSTA`. Buscarlas
     * en el primer nivel devolvía los nombres de las abogadas y no emparejaba
     * nada. Cada carpeta se devuelve con su ruta para poder leer la tabla.
     *
     * @return array<int, array>
     */
    /**
     * Nombre de cliente a partir del nombre de la carpeta.
     *
     * Las carpetas del despacho vienen numeradas para ordenarlas en Drive
     * («2 BOLUGA», «16 COMPRAVENTA GARCES»). Ese numero es de la carpeta, no
     * del cliente, asi que se quita. Lo demas se respeta tal cual: son nombres
     * que el despacho reconoce, y adivinar razones sociales seria inventar.
     */
    protected function nombreDeCliente(string $carpeta): string
    {
        $limpio = preg_replace('/^\s*\d+\s*[-._)]?\s+/u', '', trim($carpeta));

        // Si la carpeta era solo un numero, no hay nombre que rescatar.
        return $limpio === '' ? trim($carpeta) : $limpio;
    }

    protected function carpetasDeEmpresa(DriveService $drive, string $raizId, ?string $driveId, int $nivel, string $ruta = ''): array
    {
        $carpetas = $drive->listFolders($raizId, $driveId);

        if ($nivel <= 1) {
            return array_map(function ($c) use ($ruta) {
                $c['ruta'] = $ruta;

                return $c;
            }, $carpetas);
        }

        $salida = [];
        foreach ($carpetas as $c) {
            $salida = array_merge(
                $salida,
                $this->carpetasDeEmpresa($drive, $c['id'], $driveId, $nivel - 1, trim($ruta.'/'.$c['name'], '/'))
            );
        }

        return $salida;
    }

    protected function listarUnidades(DriveService $drive): int
    {
        $unidades = $drive->listSharedDrives();

        if ($unidades === []) {
            $this->warn('La cuenta conectada no ve ninguna unidad compartida.');

            return self::FAILURE;
        }

        $this->table(['Id', 'Nombre'], array_map(fn ($u) => [$u['id'], $u['name']], $unidades));
        $this->line('Fija la elegida en .env con DRIVE_SHARED_DRIVE_ID=<id>.');

        return self::SUCCESS;
    }

    /**
     * Propone el cliente que mejor corresponde al nombre de una carpeta.
     * Método puro (sin red ni BD propia): testeable con una colección en memoria.
     *
     * @param  Collection<int, Client>  $clientes
     * @return array{cliente: Client, score: float, motivo: string}|null
     */
    public function sugerirCliente(string $nombreCarpeta, Collection $clientes): ?array
    {
        $digitos = preg_replace('/\D+/', '', $nombreCarpeta) ?? '';
        $normalizado = $this->normalizar($nombreCarpeta);

        $mejor = null;

        foreach ($clientes as $cliente) {
            $nitCliente = preg_replace('/\D+/', '', (string) $cliente->nit) ?? '';

            // El NIT dentro del nombre de la carpeta es prácticamente inequívoco.
            if (strlen($nitCliente) >= 7 && $digitos !== '' && str_contains($digitos, $nitCliente)) {
                return ['cliente' => $cliente, 'score' => 1.0, 'motivo' => 'NIT'];
            }

            $razon = $this->normalizar((string) $cliente->razon_social);
            if ($razon === '' || $normalizado === '') {
                continue;
            }

            $score = 0.0;
            $motivo = 'nombre';

            if ($razon === $normalizado) {
                $score = 0.95;
            } elseif (str_contains($normalizado, $razon) || str_contains($razon, $normalizado)) {
                $score = 0.8;
                $motivo = 'nombre contenido';
            } else {
                similar_text($razon, $normalizado, $porcentaje);
                $score = $porcentaje / 100;
                $motivo = 'nombre similar';
            }

            if ($mejor === null || $score > $mejor['score']) {
                $mejor = ['cliente' => $cliente, 'score' => $score, 'motivo' => $motivo];
            }
        }

        return $mejor;
    }

    /**
     * Normaliza para comparar: sin tildes, sin sufijos societarios ni puntuación.
     */
    public function normalizar(string $valor): string
    {
        $valor = Str::lower(Str::ascii($valor));
        $valor = preg_replace('/\b(s\.?a\.?s|ltda|s\.?a|e\.?u|sas|cia|y cia|s en c)\b/', ' ', $valor) ?? $valor;
        $valor = preg_replace('/[^a-z0-9]+/', ' ', $valor) ?? $valor;

        return trim(preg_replace('/\s+/', ' ', $valor) ?? $valor);
    }
}
