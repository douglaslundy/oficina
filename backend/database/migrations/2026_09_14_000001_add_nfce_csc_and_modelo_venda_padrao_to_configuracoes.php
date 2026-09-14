<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito do usuário (2026-09-14): motor de NFC-e completo via
 * NFePHP + switch de qual documento usar como padrão pra venda de produtos.
 *
 * CSC (Código de Segurança do Contribuinte) e CSCId são exigidos pra montar
 * o QR Code da NFC-e (Tools::signNFe() do sped-nfe cobra os dois via
 * $this->config->CSC/CSCid — ver Common/Tools.php::addQRCode()). São
 * secrets DISTINTOS por ambiente (homologação/produção são cadastros
 * separados no portal da SEFAZ) — dois pares, não um só, pra a troca de
 * ambiente (já existente em `ambiente_fiscal`) não exigir recadastrar o CSC
 * toda vez. Token cifrado com Crypt::encryptString() (mesmo padrão já usado
 * pra senha do certificado A1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->string('csc_id_homologacao', 10)->nullable()->after('serie_nfce');
            $table->text('csc_token_homologacao_encrypted')->nullable()->after('csc_id_homologacao');
            $table->string('csc_id_producao', 10)->nullable()->after('csc_token_homologacao_encrypted');
            $table->text('csc_token_producao_encrypted')->nullable()->after('csc_id_producao');
            $table->string('modelo_venda_padrao', 10)->default('NF-e')->after('csc_token_producao_encrypted');
            // Mesmo raciocínio já aplicado à NF-e via NFePHP (proximo_numero_nfe,
            // separado de proximo_numero_nf usado por Spedy/Focus): a numeração
            // de NFC-e via NFePHP precisa ser um contador PRÓPRIO, nunca
            // compartilhado com proximo_numero_nfce (Spedy/Focus) — se a
            // oficina algum dia trocar de provedor, misturar os dois contadores
            // produziria número duplicado ou pulado numa mesma série, o que é
            // uma violação fiscal real (numeração de NF deve ser sequencial e
            // sem furos por modelo+série).
            $table->integer('proximo_numero_nfce_nfephp')->default(1)->after('modelo_venda_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn([
                'csc_id_homologacao', 'csc_token_homologacao_encrypted',
                'csc_id_producao', 'csc_token_producao_encrypted',
                'modelo_venda_padrao', 'proximo_numero_nfce_nfephp',
            ]);
        });
    }
};
