<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\CertificadoValidator;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use App\Services\Fiscal\NfePhp\MotorNfe;
use App\Services\Fiscal\NfePhp\MotorNfse;

class NfePhpProvider implements FiscalProvider, ConsultaNotaTerceiroProvider
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
        return $nota->modelo === 'NFE'
            ? app(MotorNfe::class)->emitir($nota, $this->ambiente)
            : app(MotorNfse::class)->emitir($nota, $this->ambiente);
    }

    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado
    {
        return $modelo === 'NFE'
            ? app(MotorNfe::class)->consultar($referencia, $this->ambiente)
            : app(MotorNfse::class)->consultar($referencia, $this->ambiente);
    }

    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
    {
        if ($modelo === 'NFE') {
            // MotorNfe::cancelar() exige o protocolo original (sefazCancela()
            // não aceita só a chave) — NfePhpProvider não tem acesso à
            // NotaFiscal aqui (só à referência/motivo, mesma limitação da
            // interface genérica). O controller precisa buscar o protocolo
            // e usar uma via alternativa — ver Task 7 pra como isso é
            // resolvido no NotaFiscalController::cancelar().
            throw new \RuntimeException('Cancelamento de NF-e via NfePHP requer o protocolo original — chame MotorNfe::cancelar() diretamente a partir do controller, não via FiscalProvider::cancelar().');
        }

        return app(MotorNfse::class)->cancelar($referencia, $motivo, $this->ambiente);
    }

    /**
     * Consulta de NF-e de terceiro (nota de entrada) — ao contrário de
     * Spedy/Focus, aqui não há provedor intermediário: o MotorNfe fala
     * direto com o webservice nacional de Distribuição DFe usando o
     * certificado A1 da própria oficina.
     */
    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado
    {
        return app(MotorNfe::class)->consultarNotaRecebida($chaveAcesso, $this->ambiente);
    }

    /**
     * @return list<\App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo>
     */
    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array
    {
        // $desde fica sem uso: a Distribuição DFe só ordena/pagina por NSU
        // incremental, não filtra por data de emissão — mesma limitação já
        // documentada em FocusNfeProvider::listarNotasRecebidas(). Mantido
        // na assinatura por exigência da interface.
        return app(MotorNfe::class)->listarNotasRecebidas($cnpjOficina, $this->ambiente);
    }
}
