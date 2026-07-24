<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horários em America/Sao_Paulo explícito — o timezone da app (config/app.php)
// é UTC, então sem isso os horários abaixo disparariam 3h mais cedo (ex.:
// dailyAt('06:00') rodaria às 03:00 de Brasília). Não muda onde/como datas
// são armazenadas no resto do sistema, só a hora em que o agendador dispara.
Schedule::command('oficina:recalcular-status-clientes')->dailyAt('02:00')->timezone('America/Sao_Paulo');
Schedule::command('alertas:verificar')->dailyAt('07:00')->timezone('America/Sao_Paulo');
Schedule::command('cobrancas:gerar')->dailyAt('06:00')->timezone('America/Sao_Paulo');
