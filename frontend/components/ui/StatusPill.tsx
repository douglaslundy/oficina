const STATUS_MAP: Record<string, { label: string; cls: string }> = {
  REGULAR:          { label: 'Regular',       cls: 'pill-success' },
  DEVEDOR:          { label: 'Devedor',        cls: 'pill-danger'  },
  DIVIDA_VENCIDA:   { label: 'Dívida Vencida', cls: 'pill-danger'  },
  OS_ABERTA:        { label: 'OS Aberta',      cls: 'pill-accent'  },
  ATIVO:            { label: 'Ativo',          cls: 'pill-success' },
  INATIVO:          { label: 'Inativo',        cls: 'pill-muted'   },
  ABERTA:           { label: 'Aberta',         cls: 'pill-info'    },
  EM_ANDAMENTO:     { label: 'Em Andamento',   cls: 'pill-accent'  },
  AGUARDANDO_PECAS: { label: 'Aguard. Peças',  cls: 'pill-muted'   },
  CONCLUIDA:        { label: 'Concluída',      cls: 'pill-success' },
  CANCELADA:        { label: 'Cancelada',      cls: 'pill-danger'  },
  // Estados de orçamento (pré-OS) reaproveitam a mesma cor semântica do
  // status "final" equivalente (ORÇAMENTO_RECUSADO ~ vermelho de CANCELADA,
  // ORÇAMENTO_APROVADO ~ verde de CONCLUIDA etc.) — a variante "contorno"
  // (pill-outline) os mantém visualmente distintos na coluna de Status.
  ORCAMENTO_ENVIADO:  { label: 'Orçamento Enviado',   cls: 'pill-info pill-outline'    },
  ORCAMENTO_APROVADO: { label: 'Orçamento Aprovado',  cls: 'pill-success pill-outline' },
  ORCAMENTO_PARCIAL:  { label: 'Orçamento Parcial',   cls: 'pill-accent pill-outline'  },
  ORCAMENTO_RECUSADO: { label: 'Orçamento Recusado',  cls: 'pill-danger pill-outline'  },
  NORMAL:           { label: 'Normal',         cls: 'pill-success' },
  BAIXO:            { label: 'Baixo',          cls: 'pill-accent'  },
  CRITICO:          { label: 'Crítico',        cls: 'pill-danger'  },
  SEM_ESTOQUE:      { label: 'Sem Estoque',    cls: 'pill-danger'  },
  RASCUNHO:         { label: 'Rascunho',       cls: 'pill-muted'   },
  PROCESSANDO:      { label: 'Processando',    cls: 'pill-accent'  },
  AUTORIZADA:       { label: 'Autorizada',     cls: 'pill-success' },
  REJEITADA:        { label: 'Rejeitada',      cls: 'pill-danger'  },
  ERRO:             { label: 'Erro',           cls: 'pill-danger'  },
  CONTINGENCIA:     { label: 'Contingência',   cls: 'pill-accent'  },
  PENDENTE:         { label: 'Pendente',       cls: 'pill-accent'  },
  PAGA:             { label: 'Paga',           cls: 'pill-success' },
  VENCIDA:          { label: 'Vencida',        cls: 'pill-danger'  },
  CONFERIDA:        { label: 'Conferida',      cls: 'pill-success' },
  SEM_CHAVE:        { label: 'Sem chave',      cls: 'pill-muted'   },
}

export function StatusPill({ status }: { status: string }) {
  const s = STATUS_MAP[status] ?? { label: status, cls: 'pill-muted' }
  return <span className={`pill ${s.cls}`}>{s.label}</span>
}
