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
        $data = $config->toArray();
        $data['tem_certificado'] = !empty($config->certificado_pfx_encrypted);
        unset($data['certificado_pfx_encrypted']);
        return response()->json($data);
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
            'cep'                   => ['nullable', 'string', 'max:9'],
            'endereco'              => ['nullable', 'string', 'max:200'],
            'cidade'                => ['nullable', 'string', 'max:80'],
            'uf'                    => ['nullable', 'string', 'size:2'],
            'telefone'              => ['nullable', 'string', 'max:15'],
            'email'                 => ['nullable', 'email', 'max:120'],
            'ambiente_fiscal'       => ['nullable', 'in:PRODUCAO,HOMOLOGACAO'],
            'serie_nf'              => ['nullable', 'string', 'max:5'],
            'aliquota_iss'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cnae'                  => ['nullable', 'string', 'max:20'],
            'codigo_ibge'           => ['nullable', 'string', 'max:10'],
            'estoque_limite_padrao' => ['nullable', 'integer', 'min:0'],
            'alertas_email'         => ['nullable', 'boolean'],
            'email_alertas'         => ['nullable', 'email', 'max:120'],
            'certificado_base64'    => ['nullable', 'string'],
        ]);

        if (!empty($validated['certificado_base64'])) {
            $validated['certificado_pfx_encrypted'] = Crypt::encryptString($validated['certificado_base64']);
        }
        unset($validated['certificado_base64']);

        $config = Configuracao::first();
        if ($config) {
            $config->update($validated);
        } else {
            $config = Configuracao::create($validated);
        }

        return response()->json(['message' => 'Configurações atualizadas.', 'data' => $config]);
    }

    public function uploadCertificado(Request $request): JsonResponse
    {
        $request->validate([
            'certificado' => ['required', 'file', 'mimes:pfx,p12', 'max:5120'],
            'senha'       => ['required', 'string'],
        ]);

        $file     = $request->file('certificado');
        $conteudo = file_get_contents($file->getRealPath());

        $pfx = [];
        if (!openssl_pkcs12_read($conteudo, $pfx, $request->senha)) {
            return response()->json(['message' => 'Certificado inválido ou senha incorreta.'], 422);
        }

        $key       = substr(hash('sha256', config('app.key'), true), 0, 32);
        $iv        = random_bytes(16);
        $encrypted = openssl_encrypt($conteudo, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored    = base64_encode($iv . $encrypted);

        $config = Configuracao::firstOrCreate([]);
        $config->update(['certificado_pfx_encrypted' => $stored]);

        return response()->json(['message' => 'Certificado enviado com sucesso.', 'tem_certificado' => true]);
    }
}
