<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BuscaTexto;
use PHPUnit\Framework\TestCase;

class BuscaTextoTest extends TestCase
{
    public function test_normalizar_tira_acento_caixa_e_espacos_extras(): void
    {
        $this->assertSame('filtro de oleo', BuscaTexto::normalizar('  FILTRO   de Óleo '));
        $this->assertSame('cacamba ninho', BuscaTexto::normalizar('Caçamba Ninho'));
        $this->assertSame('pastilha freio', BuscaTexto::normalizar('PASTILHA Fréio'));
    }

    public function test_tokens_separa_por_palavra_sem_acento(): void
    {
        $this->assertSame(['filtro', 'oleo'], BuscaTexto::tokens('Filtro  ÓLEO'));
    }

    public function test_tokens_de_texto_vazio_ou_so_espacos_e_lista_vazia(): void
    {
        $this->assertSame([], BuscaTexto::tokens(''));
        $this->assertSame([], BuscaTexto::tokens("   \t "));
    }

    public function test_escapar_like_neutraliza_curingas(): void
    {
        $this->assertSame('100\%', BuscaTexto::escaparLike('100%'));
        $this->assertSame('a\_b', BuscaTexto::escaparLike('a_b'));
        $this->assertSame('a\\\\b', BuscaTexto::escaparLike('a\\b'));
    }

    public function test_mapa_sql_tem_origem_e_destino_do_mesmo_tamanho(): void
    {
        $this->assertSame(
            mb_strlen(BuscaTexto::ACENTOS),
            mb_strlen(BuscaTexto::SEM_ACENTOS),
            'translate() do Postgres exige from/to do mesmo tamanho.'
        );
    }

    public function test_mapa_sql_e_php_concordam_em_todo_caractere(): void
    {
        // O SQL usa translate(ACENTOS→SEM_ACENTOS); o PHP tem que produzir
        // exatamente o mesmo resultado, senão o LIKE não casa.
        $origem  = mb_str_split(BuscaTexto::ACENTOS);
        $destino = mb_str_split(BuscaTexto::SEM_ACENTOS);
        foreach ($origem as $i => $ch) {
            $this->assertSame($destino[$i], BuscaTexto::normalizar($ch), "Caractere {$ch}");
        }
    }
}
