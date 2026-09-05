<?php
declare(strict_types=1);

namespace App\Exceptions;

/**
 * Dados fiscais incompletos impedem criar/emitir a nota (UF da empresa ou
 * do cliente, regime tributário, NCM/origem/tributação de ICMS pendente
 * num produto). A mensagem é feita pra ir direto pro usuário (422).
 *
 * NUNCA usar pra falha técnica/erro de rede — só pra "o usuário precisa
 * completar um cadastro antes".
 */
class EmissaoBloqueadaException extends \RuntimeException
{
}
