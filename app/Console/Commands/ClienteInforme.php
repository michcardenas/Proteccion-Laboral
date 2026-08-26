<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\InformeClienteService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera el informe en Markdown de uno o varios clientes.
 *
 * Cada informe consume API de Anthropic, así que por defecto NO se corre sobre
 * todos: hay que pedirlo con `--all` a propósito.
 */
class ClienteInforme extends Command
{
    protected $signature = 'cliente:informe
        {--client= : Id del cliente}
        {--all : Todos los clientes que tengan carpeta de Drive}
        {--no-guardar : Muestra el informe por pantalla sin guardarlo}';

    protected $description = 'Genera con IA un informe .md del cliente a partir de sus documentos de Drive';

    public function handle(InformeClienteService $servicio): int
    {
        $clientes = $this->clientes();

        if ($clientes->isEmpty()) {
            $this->error('No hay clientes que cumplan. Usa --client=<id> o --all.');

            return self::FAILURE;
        }

        $guardar = ! $this->option('no-guardar');
        $filas = [];

        foreach ($clientes as $cliente) {
            $this->line("→ <info>{$cliente->razon_social}</info>");

            try {
                $r = $servicio->generar($cliente, $guardar);
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
                $filas[] = [$cliente->razon_social, '—', '—', '—', 'ERROR'];

                continue;
            }

            $s = $r['stats'];
            $filas[] = [
                $cliente->razon_social,
                $s['total'],
                $s['legibles'],
                $s['leidos'],
                $r['ruta'] ?? '(no guardado)',
            ];

            if (! $guardar) {
                $this->newLine();
                $this->line($r['markdown']);
            }
        }

        $this->newLine();
        $this->table(['Cliente', 'Docs', 'Legibles', 'Leídos', 'Informe'], $filas);

        return self::SUCCESS;
    }

    protected function clientes()
    {
        if ($id = $this->option('client')) {
            return Client::query()->whereKey($id)->get();
        }

        if ($this->option('all')) {
            return Client::query()->whereNotNull('drive_folder_id')->get();
        }

        return Client::query()->whereRaw('1 = 0')->get();
    }
}
