<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\CertificadoValidator;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use App\Services\Fiscal\NfePhp\MotorNfse;

class NfePhpProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $ambiente,
    ) {}

    /**
     * Reinterpretado: NFePHP não registra empresa em lugar nenhum — isso
     * valida que a Configuracao tem os dados mínimos exigidos pelo leiaute
     * da NF-e/NFS-e antes de "ativar" o provedor pra esta oficina.
     */
    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        $faltando = [];
        if ($e->cnpjLimpo() === '') $faltando[] = 'CNPJ';
        if (empty($e->inscricaoEstadual)) $faltando[] = 'Inscrição Estadual';
        if (empty($e->inscricaoMunicipal)) $faltando[] = 'Inscrição Municipal';
        if (empty($e->cnae)) $faltando[] = 'CNAE';
        if (empty($e->codigoIbge)) $faltando[] = 'código IBGE do município';
        if (empty($e->regimeTributario)) $faltando[] = 'regime tributário';

        if ($faltando !== []) {
            return RegistroResultado::erro('Complete antes de ativar o NFePHP: ' . implode(', ', $faltando) . '.');
        }

        return RegistroResultado::ok($e->cnpjLimpo(), 'local');
    }

    /**
     * Reinterpretado: não envia nada a lugar nenhum. Só confere que o
     * certificado abre com a senha informada e não está vencido.
     */
    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        $validacao = (new CertificadoValidator())->validar($pfxBinary, $senha);
        if (!$validacao['ok']) {
            throw new \RuntimeException($validacao['erro'] ?? 'Certificado inválido.');
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        if ($nota->modelo === 'NFE') {
            return EmissaoResultado::rejeitada(
                'Emissão de NF-e pelo motor NFePHP ainda não disponível neste sistema. Use Focus NFe ou aguarde uma etapa futura.',
                $nota->referenciaExterna,
            );
        }

        return app(MotorNfse::class)->emitir($nota, $this->ambiente);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        return app(MotorNfse::class)->consultar($referencia, $this->ambiente);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        return app(MotorNfse::class)->cancelar($referencia, $motivo, $this->ambiente);
    }
}
