<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A sessão do Postgres precisa estar no mesmo fuso do PHP: o Laravel grava
 * now() como texto sem fuso em colunas timestamptz, e sem `timezone` na conexão
 * o banco o lia como UTC (emitido_em ficava 3h antes de criado_em — achado em
 * 2026-09-20).
 */
class DatabaseTimezoneConfigTest extends TestCase
{
    public function test_conexao_pgsql_usa_o_mesmo_fuso_do_app(): void
    {
        $this->assertSame(config('app.timezone'), config('database.connections.pgsql.timezone'));
    }
}
