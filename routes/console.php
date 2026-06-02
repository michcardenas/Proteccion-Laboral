<?php

use App\Jobs\PollGmailInbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sondea la bandeja de Gmail cada 2 minutos e ingesta los correos nuevos.
// Requiere un worker de cola (`php artisan queue:work`) y el scheduler
// (`php artisan schedule:work`) corriendo, además de una cuenta Gmail conectada.
// Si no hay cuenta conectada, el job se omite silenciosamente.
Schedule::job(new PollGmailInbox)
    ->everyTwoMinutes()
    ->withoutOverlapping();
