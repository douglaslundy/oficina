<?php
declare(strict_types=1);

return [
    // Quantos arquivos .sql.gz manter no servidor. Os mais antigos além
    // disso são removidos após cada backup (manual ou agendado).
    'manter' => (int) env('BACKUP_MANTER', 14),

    // Hora do backup automático diário (timezone America/Sao_Paulo).
    'hora_diaria' => env('BACKUP_HORA', '03:00'),
];
