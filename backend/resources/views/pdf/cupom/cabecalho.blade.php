<div class="c b g">{{ $emitente['nome'] }}</div>
@if($emitente['razao_social'] !== '' && $emitente['razao_social'] !== $emitente['nome'])<div class="c">{{ $emitente['razao_social'] }}</div>@endif
@if($emitente['cnpj'] !== '')<div class="c">CNPJ: {{ $emitente['cnpj'] }}@if($emitente['ie'] !== '') &nbsp; IE: {{ $emitente['ie'] }}@endif</div>@endif
@if($emitente['endereco'] !== '')<div class="c">{{ $emitente['endereco'] }}</div>@endif
@if($emitente['telefone'] !== '')<div class="c">Tel: {{ $emitente['telefone'] }}</div>@endif
