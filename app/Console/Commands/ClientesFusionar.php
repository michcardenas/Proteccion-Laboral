<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientDriveFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Funde dos fichas del mismo cliente en una.
 *
 * Pasa cuando una empresa tiene carpeta bajo dos abogadas en Drive y se importa
 * dos veces con nombres distintos (VISUALED y VISUAL PUBLICIDAD, con el mismo
 * NIT). La duplicación se detecta al completar los perfiles: la IA saca el
 * mismo NIT de los documentos de ambas.
 *
 * Todo lo del cliente absorbido pasa al que se queda: procesos, documentos,
 * contratos, contactos, visitas, pagos, facturas, el equipo asignado y —lo
 * importante— su carpeta de Drive, que se añade como carpeta extra para que
 * siga sincronizando. Después el absorbido se borra de forma reversible.
 *
 * Sin `--ejecutar` no toca nada: solo dice qué movería.
 */
class ClientesFusionar extends Command
{
    protected $signature = 'clientes:fusionar
        {absorbido : Id del cliente que desaparece}
        {superviviente : Id del cliente que se queda con todo}
        {--ejecutar : Hace la fusión de verdad. Sin esto solo informa}';

    protected $description = 'Funde dos fichas del mismo cliente, moviendo todo al superviviente';

    /** tabla => descripción para el informe. */
    private const TABLAS = [
        'processes' => 'procesos',
        'documents' => 'documentos',
        'contracts' => 'contratos',
        'client_contacts' => 'contactos',
        'visits' => 'visitas',
        'payments' => 'pagos',
        'invoices' => 'facturas',
        'client_user' => 'asignaciones de equipo',
        'client_drive_folders' => 'carpetas de Drive extra',
    ];

    public function handle(): int
    {
        $absorbido = Client::find($this->argument('absorbido'));
        $superviviente = Client::find($this->argument('superviviente'));

        if (! $absorbido || ! $superviviente) {
            $this->error('Alguno de los dos clientes no existe.');

            return self::FAILURE;
        }

        if ($absorbido->id === $superviviente->id) {
            $this->error('Son el mismo cliente.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Absorbe:      <fg=green>{$superviviente->razon_social}</> (id {$superviviente->id}, NIT ".($superviviente->nit ?: '—').')');
        $this->line("Desaparece:   <fg=red>{$absorbido->razon_social}</> (id {$absorbido->id}, NIT ".($absorbido->nit ?: '—').')');
        $this->newLine();

        $filas = [];
        foreach (self::TABLAS as $tabla => $etiqueta) {
            $n = DB::table($tabla)->where('client_id', $absorbido->id)->count();
            if ($n > 0) {
                $filas[] = [$etiqueta, $n];
            }
        }

        // Su carpeta principal de Drive tambien se mueve: sin esto sus
        // documentos dejarian de sincronizar y quedarian congelados.
        if ($absorbido->drive_folder_id) {
            $filas[] = ['carpeta de Drive principal', $absorbido->drive_folder_name ?: $absorbido->drive_folder_id];
        }

        if ($filas === []) {
            $this->warn('El cliente absorbido no tiene nada colgando. Solo se borrará.');
        } else {
            $this->table(['Qué se mueve', 'Cuánto'], $filas);
        }

        // Datos que el superviviente no tiene y el absorbido si: se rescatan.
        $rescatables = [];
        foreach (['nit', 'dv', 'ciudad', 'sector', 'contacto_principal', 'email', 'telefono'] as $campo) {
            if (blank($superviviente->{$campo}) && filled($absorbido->{$campo})) {
                $rescatables[$campo] = $absorbido->{$campo};
            }
        }

        if ($rescatables !== []) {
            $this->newLine();
            $this->line('Datos que se rescatan del absorbido porque el superviviente los tiene vacíos:');
            $this->table(['Campo', 'Valor'], collect($rescatables)->map(fn ($v, $k) => [$k, $v])->values()->all());
        }

        if (! $this->option('ejecutar')) {
            $this->newLine();
            $this->info('Nada se tocó. Repite con --ejecutar para fusionar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($absorbido, $superviviente, $rescatables) {
            foreach (array_keys(self::TABLAS) as $tabla) {
                if ($tabla === 'client_user') {
                    // Pivote con clave compuesta: mover a ciegas duplicaria a
                    // quien ya este asignado a los dos clientes.
                    $yaAsignados = DB::table('client_user')
                        ->where('client_id', $superviviente->id)
                        ->pluck('user_id');

                    DB::table('client_user')
                        ->where('client_id', $absorbido->id)
                        ->whereIn('user_id', $yaAsignados)
                        ->delete();
                }

                DB::table($tabla)
                    ->where('client_id', $absorbido->id)
                    ->update(['client_id' => $superviviente->id]);
            }

            // La carpeta principal del absorbido pasa a ser una carpeta mas del
            // superviviente, que ya tiene la suya.
            if ($absorbido->drive_folder_id) {
                ClientDriveFolder::updateOrCreate(
                    ['drive_folder_id' => $absorbido->drive_folder_id],
                    [
                        'client_id' => $superviviente->id,
                        'drive_folder_name' => $absorbido->drive_folder_name,
                    ],
                );
            }

            // ORDEN: primero se le quita al absorbido lo que es unico, y solo
            // despues se le pasa al superviviente. Al reves chocan por el indice
            // unico del NIT, que es justo el caso que motiva la fusion: los dos
            // tienen el mismo.
            $absorbido->forceFill([
                'nit' => null,
                'drive_folder_id' => null,
                'notas' => trim(($absorbido->notas ?? '')."\nFusionado en «{$superviviente->razon_social}» (id {$superviviente->id})."),
            ])->save();

            if ($rescatables !== []) {
                $superviviente->forceFill($rescatables)->save();
            }

            // La ficha del superviviente queda incompleta: ahora tiene el doble
            // de documentos y su resumen no los ha visto.
            $superviviente->forceFill(['resumen_documental_at' => null])->save();

            $absorbido->delete();
        });

        $this->newLine();
        $this->info("Fusionado. «{$absorbido->razon_social}» ya no existe; todo está en «{$superviviente->razon_social}».");
        $this->warn('La ficha de conocimiento quedó marcada como desactualizada: ahora hay más documentos de los que había leído. Regenérala.');

        return self::SUCCESS;
    }
}
