<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Configuracao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ConfiguracaoController extends Controller
{
    public function show(): JsonResponse
    {
        $config = Configuracao::first() ?? new Configuracao();
        return response()->json($this->sanitizar($config));
    }

    /**
     * Bug real de produção (2026-09-14, achado ao testar o switch de NFC-e
     * recém-criado): `update()` devolvia `$config` inteiro, cru — nunca
     * passava pelo mesmo filtro que `show()` já aplicava. Isso expunha em
     * texto (o ciphertext, não o segredo em si, mas mesmo assim nunca
     * deveria sair do backend) o PFX do certificado A1 inteiro, a senha
     * cifrada E — o motivo de eu ter reparado nisso agora — o token CSC
     * recém-adicionado. Extraído num método só, reusado pelos dois
     * endpoints, pra nunca mais divergir.
     */
    private function sanitizar(Configuracao $config): array
    {
        $data = $config->toArray();
        $data['tem_certificado'] = !empty($config->certificado_pfx_encrypted);
        $data['tem_csc_homologacao'] = !empty($config->csc_token_homologacao_encrypted);
        $data['tem_csc_producao']    = !empty($config->csc_token_producao_encrypted);
        unset(
            $data['certificado_pfx_encrypted'],
            $data['certificado_senha_encrypted'],
            $data['csc_token_homologacao_encrypted'],
            $data['csc_token_producao_encrypted'],
        );
        return $data;
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'razao_social'          => ['nullable', 'string', 'max:150'],
            'nome_fantasia'         => ['nullable', 'string', 'max:100'],
            'cnpj'                  => ['nullable', 'string', 'max:18'],
            'inscricao_estadual'    => ['nullable', 'string', 'max:30'],
            'inscricao_municipal'   => ['nullable', 'string', 'max:20'],
            'regime_tributario'     => ['nullable', 'string', 'max:30'],
            // Mesmo fix de ClienteController (2026-09-14): CEP inválido (não
            // 8 dígitos) só quebrava muito mais tarde, na emissão de NF-e —
            // e aqui é pior, corrompe TODA nota emitida por esta oficina,
            // não só as de um cliente.
            'cep'                   => ['nullable', 'string', 'max:9', 'regex:/^\d{5}-?\d{3}$/'],
            'endereco'              => ['nullable', 'string', 'max:200'],
            'logradouro'            => ['nullable', 'string', 'max:150'],
            'numero'                => ['nullable', 'string', 'max:20'],
            'bairro'                => ['nullable', 'string', 'max:80'],
            'cidade'                => ['nullable', 'string', 'max:80'],
            'uf'                    => ['nullable', 'string', 'size:2'],
            'telefone'              => ['nullable', 'string', 'max:15'],
            'email'                 => ['nullable', 'email', 'max:120'],
            'ambiente_fiscal'       => ['nullable', 'in:PRODUCAO,HOMOLOGACAO'],
            'calculo_tributario_modo' => ['nullable', 'in:MANUAL,AUTOMATICO_PROVEDOR'],
            'serie_nf'              => ['nullable', 'string', 'max:5'],
            'aliquota_iss'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cnae'                  => ['nullable', 'string', 'max:20'],
            'codigo_ibge'           => ['nullable', 'string', 'max:10'],
            'estoque_limite_padrao' => ['nullable', 'integer', 'min:0'],
            'alertas_email'         => ['nullable', 'boolean'],
            'email_alertas'         => ['nullable', 'email', 'max:120'],
            'certificado_base64'    => ['nullable', 'string'],
            'markup_padrao_entrada_nf'   => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'atualizar_custo_entrada_nf' => ['nullable', 'boolean'],
            // Pedido explícito do usuário (2026-09-14): switch de qual
            // documento fiscal usar pra venda de produtos, com NF-e como
            // padrão (ver CriarNotaFiscalService::criar()) — e CSC/CSCId
            // (Código de Segurança do Contribuinte), exigidos pelo motor de
            // NFC-e (MotorNfce) pra montar o QR Code. Pares distintos por
            // ambiente porque a SEFAZ cadastra CSC separado pra homologação
            // e produção.
            'modelo_venda_padrao'   => ['nullable', 'in:NF-e,NFC-e'],
            'csc_id_homologacao'    => ['nullable', 'string', 'max:10'],
            'csc_token_homologacao' => ['nullable', 'string'],
            'csc_id_producao'       => ['nullable', 'string', 'max:10'],
            'csc_token_producao'    => ['nullable', 'string'],
        ]);

        if (!empty($validated['certificado_base64'])) {
            $validated['certificado_pfx_encrypted'] = Crypt::encryptString($validated['certificado_base64']);
        }
        unset($validated['certificado_base64']);

        if (!empty($validated['csc_token_homologacao'])) {
            $validated['csc_token_homologacao_encrypted'] = Crypt::encryptString($validated['csc_token_homologacao']);
        }
        unset($validated['csc_token_homologacao']);

        if (!empty($validated['csc_token_producao'])) {
            $validated['csc_token_producao_encrypted'] = Crypt::encryptString($validated['csc_token_producao']);
        }
        unset($validated['csc_token_producao']);

        $config = Configuracao::first();
        if ($config) {
            $config->update($validated);
        } else {
            $config = Configuracao::create($validated);
        }

        return response()->json(['message' => 'Configurações atualizadas.', 'data' => $this->sanitizar($config)]);
    }

    public function uploadCertificado(Request $request, \App\Services\Fiscal\CertificadoValidator $validator): JsonResponse
    {
        // Valida pela extensão do arquivo, não pelo mime-type: arquivos PKCS#12
        // (.pfx/.p12) são binários DER e o fileinfo do PHP os reporta como
        // application/octet-stream (ou x-pkcs12, conforme a versão do libmagic),
        // o que fazia a regra `mimes:pfx,p12` rejeitar certificados válidos.
        // O conteúdo é validado de verdade logo abaixo por openssl_pkcs12_read().
        $request->validate([
            'certificado' => ['required', 'file', 'extensions:pfx,p12', 'max:5120'],
            'senha'       => ['required', 'string'],
        ], [
            'certificado.extensions' => 'O certificado digital A1 deve ser um arquivo .pfx ou .p12.',
        ]);

        $file     = $request->file('certificado');
        $conteudo = file_get_contents($file->getRealPath());

        $resultado = $validator->validar($conteudo, $request->senha);
        if (!$resultado['ok']) {
            return response()->json(['message' => $resultado['erro'] ?? 'Certificado inválido.'], 422);
        }

        // Achado de segurança (auditoria 2026-09-14): antes cifrava com
        // AES-256-CBC "na mão", derivando a chave de um único SHA-256 do
        // APP_KEY, sem autenticação (sem HMAC/tag) — um blob adulterado no
        // banco não seria detectado antes de tentar usá-lo pra assinar
        // documentos fiscais. `Crypt::encryptString()` (já usado 2 linhas
        // abaixo pra senha do certificado, curiosamente não pra chave
        // privada em si) usa AES-256-CBC-HMAC autenticado — mesma proteção,
        // sem chave derivada à mão. `RegistrarEmissorService::decifrarPfx()`
        // aceita os dois formatos (o antigo só como fallback de leitura,
        // pra não travar certificados já armazenados) — todo upload novo
        // já sai no formato novo.
        $stored = \Illuminate\Support\Facades\Crypt::encryptString($conteudo);

        $config = Configuracao::firstOrCreate([]);
        $config->update([
            'certificado_pfx_encrypted'   => $stored,
            'certificado_senha_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($request->senha),
            'certificado_validade'        => $resultado['validade'],
            'certificado_nome'            => $resultado['nome'] ?? $file->getClientOriginalName(),
            'certificado_status'          => 'OK',
        ]);

        return response()->json([
            'message'         => 'Certificado enviado com sucesso.',
            'tem_certificado' => true,
            'validade'        => $resultado['validade'],
        ]);
    }

    public function ativarEmissao(\App\Services\Fiscal\RegistrarEmissorService $service): JsonResponse
    {
        $oficinaId = \App\Tenancy\TenancyContext::get();
        if (!$oficinaId) {
            return response()->json(['message' => 'Tenant não identificado.'], 422);
        }

        $resultado = $service->registrar($oficinaId);
        return response()->json(['message' => $resultado['mensagem']], $resultado['ok'] ? 200 : 422);
    }
}
