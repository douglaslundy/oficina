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
  NORMAL:           { label: 'Normal',         cls: 'pill-success' },
  BAIXO:            { label: 'Baixo',          cls: 'pill-accent'  },
  CRITICO:          { label: 'Crítico',        cls: 'pill-danger'  },
  SEM_ESTOQUE:      { label: 'Sem Estoque',    cls: 'pill-danger'  },
  RASCUNHO:         { label: 'Rascunho',       cls: 'pill-muted'   },
  PROCESSANDO:      { label: 'Processando',    cls: 'pill-accent'  },
  AUTORIZADA:       { label: 'Autorizada',     cls: 'pill-success' },
  REJEITADA:        { label: 'Rejeitada',      cls: 'pill-danger'  },
}

export function StatusPill({ status }: { status: string }) {
  const s = STATUS_MAP[status] ?? { label: status, cls: 'pill-muted' }
  return <span className={`pill ${s.cls}`}>{s.label}</span>
}
