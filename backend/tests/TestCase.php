<?php

namespace Tests;

use App\Models\Oficina;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cria uma Oficina válida para testes. `cnpj` é NOT NULL UNIQUE no banco
     * real (só descoberto quando os feature tests passaram a rodar contra
     * Postgres de verdade) — este helper garante um CNPJ único por chamada.
     */
    protected function criarOficina(array $attrs = []): Oficina
    {
        static $seq = 0;
        $seq++;

        return Oficina::create(array_merge([
            'nome'   => 'Oficina Teste ' . $seq,
            'slug'   => 'oficina-teste-' . $seq,
            'cnpj'   => str_pad((string) $seq, 14, '0', STR_PAD_LEFT),
            'status' => 'ATIVA',
        ], $attrs));
    }
}
