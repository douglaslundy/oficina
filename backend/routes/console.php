<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('oficina:recalcular-status-clientes')->dailyAt('02:00');
Schedule::command('alertas:verificar')->dailyAt('07:00');
Schedule::command('cobrancas:gerar')->dailyAt('06:00');
