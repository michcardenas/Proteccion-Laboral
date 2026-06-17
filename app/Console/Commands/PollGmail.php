<?php

namespace App\Console\Commands;

use App\Jobs\PollGmailInbox;
use Illuminate\Console\Command;

/**
 * Punto de entrada directo para el cron de hosting compartido (Hostinger).
 *
 * El cron llama `php artisan gmail:poll` al intervalo que permita el plan; así
 * NO depende de la coincidencia de minutos del scheduler (`everyTwoMinutes`).
 * Con QUEUE_CONNECTION=sync todo el pipeline (traer + clasificar IA + enrutar +
 * marcar leído) corre inline, sin worker.
 */
class PollGmail extends Command
{
    protected $signature = 'gmail:poll {--max=50 : Máximo de correos a traer por corrida}';

    protected $description = 'Sondea Gmail e ingesta/clasifica los correos no leídos (cron de hosting compartido).';

    public function handle(): int
    {
        $this->info('Sondeando bandeja de Gmail…');

        // dispatch_sync corre el sondeo inline. El procesamiento de cada correo
        // (ProcessInboundEmail) respeta QUEUE_CONNECTION: con `sync` también es inline.
        dispatch_sync(new PollGmailInbox((int) $this->option('max')));

        $this->info('Sondeo completado.');

        return self::SUCCESS;
    }
}
