<?php
declare(strict_types=1);

return [
    // Quantos arquivos .sql.gz manter no servidor. Os mais antigos além
    // disso são removidos após cada backup (manual ou agendado).
    'manter' => (int) env('BACKUP_MANTER', 14),

    // Hora do backup automático diário (timezone America/Sao_Paulo).
    'hora_diaria' => env('BACKUP_HORA', '03:00'),

    // Se preenchida, cada backup é cifrado com AES-256-CBC (openssl,
    // -pbkdf2 -salt) e salvo como .sql.gz.enc. GUARDE ESTA SENHA FORA DO
    // SERVIDOR — sem ela os backups cifrados são irrecuperáveis. Para
    // decifrar manualmente:
    //   openssl enc -d -aes-256-cbc -pbkdf2 -in arquivo.sql.gz.enc \
    //     -out arquivo.sql.gz -pass pass:SUA_SENHA
    'passphrase' => env('BACKUP_PASSPHRASE'),
];
