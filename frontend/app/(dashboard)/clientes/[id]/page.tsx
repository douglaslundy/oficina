'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { ClienteForm } from '@/components/forms/ClienteForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

interface OsResumo {
  id: string
  numero: number
  tipo: string
  status: string
  valor_total: number
  valor_pago: number
  saldo_devedor: number
  criado_em: string
  venda_a_prazo: boolean
  prazo_pagamento_dias?: number
  data_vencimento_pagamento?: string
}

interface ClienteData {
  id: string
  nome: string
  status: string
  cpf_cnpj: string
  telefone?: string
  email?: string
  cep?: string
  endereco?: string
  bairro?: string
  cidade?: string
  uf?: string
  veiculo_modelo?: string
  veiculo_ano?: number
  veiculo_placa?: string
}

interface Veiculo {
  id: string
  modelo: string
  ano?: number | null
  placa?: string | null
  ativo: boolean
}

function diasParaVencimento(dataVenc: string): number {
  // dataVenc vem como dd/mm/yyyy
  const [d, m, y] = dataVenc.split('/')
  const venc = new Date(Number(y), Number(m) - 1, Number(d))
  const hoje = new Date()
  hoje.setHours(0, 0, 0, 0)
  return Math.ceil((venc.getTime() - hoje.getTime()) / (1000 * 60 * 60 * 24))
}

export default function ClienteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [cliente, setCliente] = useState<ClienteData | null>(null)
  const [os, setOs] = useState<OsResumo[]>([])
  const [veiculos, setVeiculos] = useState<Veiculo[]>([])
  const [loading, setLoading] = useState(true)
  const [addingVeiculo, setAddingVeiculo] = useState(false)
  const [novoVeiculo, setNovoVeiculo] = useState({ modelo: '', ano: '', placa: '' })

  useEffect(() => {
    Promise.all([
      api.get(`/clientes/${id}`),
      api.get(`/os?cliente_id=${id}&tipo=OS,VENDA_BALCAO`),
      api.get(`/clientes/${id}/veiculos`),
    ]).then(([c, o, v]) => {
      setCliente(c.data.data)
      setOs(o.data.data ?? [])
      setVeiculos(Array.isArray(v.data) ? v.data : (v.data?.data ?? []))
    }).catch(() => {})
    .finally(() => setLoading(false))
  }, [id])

  async function handleAdicionarVeiculo() {
    if (!novoVeiculo.modelo) return
    try {
      await api.post(`/clientes/${id}/veiculos`, {
        modelo: novoVeiculo.modelo,
        ano:    novoVeiculo.ano ? Number(novoVeiculo.ano) : null,
        placa:  novoVeiculo.placa || null,
      })
      toast('Veículo adicionado.', 'success')
      setNovoVeiculo({ modelo: '', ano: '', placa: '' })
      setAddingVeiculo(false)
      const res = await api.get(`/clientes/${id}/veiculos`)
      setVeiculos(Array.isArray(res.data) ? res.data : (res.data?.data ?? []))
    } catch {
      toast('Erro ao adicionar veículo.', 'danger')
    }
  }

  async function handleRemoverVeiculo(veiculoId: string) {
    if (!confirm('Remover este veículo?')) return
    try {
      await api.delete(`/veiculos/${veiculoId}`)
      toast('Veículo removido.', 'success')
      setVeiculos(prev => prev.filter(v => v.id !== veiculoId))
    } catch {
      toast('Erro ao remover veículo.', 'danger')
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!cliente) return <p style={{ color: 'var(--danger)' }}>Cliente não encontrado.</p>

  // Débitos: OS concluídas com saldo em aberto
  const osComDebito = os.filter(o => o.status === 'CONCLUIDA' && o.saldo_devedor > 0)
  const totalDevedor = osComDebito.reduce((acc, o) => acc + o.saldo_devedor, 0)

  // Separar vencidas das a vencer
  const osVencidas = osComDebito.filter(o =>
    o.venda_a_prazo && o.data_vencimento_pagamento && diasParaVencimento(o.data_vencimento_pagamento) < 0
  )
  const osAVencer = osComDebito.filter(o =>
    o.venda_a_prazo && o.data_vencimento_pagamento && diasParaVencimento(o.data_vencimento_pagamento) >= 0
  )
  const osSemPrazo = osComDebito.filter(o => !o.venda_a_prazo)

  const totalVencido  = osVencidas.reduce((a, o) => a + o.saldo_devedor, 0)
  const totalAVencer  = osAVencer.reduce((a, o) => a + o.saldo_devedor, 0)
  const totalImediato = osSemPrazo.reduce((a, o) => a + o.saldo_devedor, 0)

  const temDivida = osComDebito.length > 0

  return (
    <div style={{ maxWidth: 1620, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>← Voltar</button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>{cliente.nome}</h1>
        <StatusPill status={cliente.status} />
        {temDivida && (
          <span style={{ background: 'rgba(229,57,53,0.15)', color: 'var(--danger)', borderRadius: 8, padding: '4px 12px', fontSize: 14, fontWeight: 700 }}>
            Total em aberto: {formatarMoeda(totalDevedor)}
          </span>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.3fr 0.7fr', gap: 24 }}>
        {/* Coluna esquerda: formulário + débitos */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Editar dados</h3>
            <ClienteForm initialData={cliente} onSuccess={() => api.get(`/clientes/${id}`).then(r => setCliente(r.data.data))} />
          </div>

          {/* Seção de débitos */}
          {temDivida && (
            <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--danger)', padding: 24 }}>
              <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--danger)', marginBottom: 4 }}>
                Débitos em Aberto
              </h3>
              <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 16 }}>
                OS concluídas com pagamento pendente
              </p>

              {/* Totalizadores */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 20 }}>
                {totalVencido > 0 && (
                  <div style={{ background: 'rgba(229,57,53,0.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '12px 16px' }}>
                    <p style={{ color: 'var(--muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 4px' }}>Vencido</p>
                    <p className="font-mono" style={{ color: 'var(--danger)', fontSize: 18, fontWeight: 700, margin: 0 }}>{formatarMoeda(totalVencido)}</p>
                  </div>
                )}
                {totalAVencer > 0 && (
                  <div style={{ background: 'rgba(245,166,35,0.1)', border: '1px solid var(--accent)', borderRadius: 8, padding: '12px 16px' }}>
                    <p style={{ color: 'var(--muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 4px' }}>A Vencer</p>
                    <p className="font-mono" style={{ color: 'var(--accent)', fontSize: 18, fontWeight: 700, margin: 0 }}>{formatarMoeda(totalAVencer)}</p>
                  </div>
                )}
                {totalImediato > 0 && (
                  <div style={{ background: 'rgba(229,57,53,0.08)', border: '1px solid var(--border)', borderRadius: 8, padding: '12px 16px' }}>
                    <p style={{ color: 'var(--muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 4px' }}>Pagamento imediato</p>
                    <p className="font-mono" style={{ color: 'var(--danger)', fontSize: 18, fontWeight: 700, margin: 0 }}>{formatarMoeda(totalImediato)}</p>
                  </div>
                )}
              </div>

              {/* Lista de OS com débito */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {osComDebito.map(o => {
                  const dias = o.data_vencimento_pagamento ? diasParaVencimento(o.data_vencimento_pagamento) : null
                  const vencida = dias !== null && dias < 0
                  const cor = vencida ? 'var(--danger)' : dias !== null ? 'var(--accent)' : 'var(--danger)'
                  const bgCor = vencida ? 'rgba(229,57,53,0.08)' : dias !== null ? 'rgba(245,166,35,0.08)' : 'rgba(229,57,53,0.05)'

                  return (
                    <div
                      key={o.id}
                      onClick={() => router.push(o.tipo === 'VENDA_BALCAO' ? `/pdv/${o.id}` : `/os/${o.id}`)}
                      style={{
                        display: 'flex', justifyContent: 'space-between', alignItems: 'center',
                        padding: '12px 14px', borderRadius: 8,
                        background: bgCor, border: `1px solid ${cor}`,
                        cursor: 'pointer',
                      }}
                    >
                      <div>
                        <p style={{ color: 'var(--text)', fontSize: 14, margin: 0, fontWeight: 600 }}>
                          {o.tipo === 'VENDA_BALCAO' ? 'Venda' : 'OS'} #{o.numero}
                          {o.venda_a_prazo && (
                            <span style={{ marginLeft: 8, fontSize: 11, color: 'var(--muted)', fontWeight: 400 }}>• prazo {o.prazo_pagamento_dias}d</span>
                          )}
                        </p>
                        {o.data_vencimento_pagamento ? (
                          <p style={{ color: cor, fontSize: 12, margin: '3px 0 0', fontWeight: 600 }}>
                            {vencida
                              ? `Venceu há ${Math.abs(dias!)} dia${Math.abs(dias!) !== 1 ? 's' : ''} — ${o.data_vencimento_pagamento}`
                              : `Vence em ${dias} dia${dias !== 1 ? 's' : ''} — ${o.data_vencimento_pagamento}`}
                          </p>
                        ) : (
                          <p style={{ color: 'var(--danger)', fontSize: 12, margin: '3px 0 0' }}>Pagamento imediato</p>
                        )}
                      </div>
                      <div style={{ textAlign: 'right' }}>
                        <p className="font-mono" style={{ color: cor, fontSize: 15, fontWeight: 700, margin: 0 }}>
                          {formatarMoeda(o.saldo_devedor)}
                        </p>
                        <p style={{ color: 'var(--muted)', fontSize: 11, margin: '2px 0 0' }}>em aberto</p>
                      </div>
                    </div>
                  )
                })}
              </div>
            </div>
          )}
        </div>

        {/* Coluna direita: histórico de OS + veículos */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Histórico de OS</h3>
          {os.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhuma OS encontrada.</p>
          ) : (
            os.map(o => (
              <div
                key={o.id}
                onClick={() => router.push(o.tipo === 'VENDA_BALCAO' ? `/pdv/${o.id}` : `/os/${o.id}`)}
                style={{ padding: '10px 0', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: 'pointer' }}
              >
                <div>
                  <p style={{ color: 'var(--text)', fontSize: 14, margin: 0, fontWeight: 600 }}>
                    {o.tipo === 'VENDA_BALCAO' ? 'Venda' : 'OS'} #{o.numero}
                  </p>
                  <p style={{ color: 'var(--muted)', fontSize: 12, margin: '2px 0 0' }}>{formatarData(o.criado_em)}</p>
                  {o.data_vencimento_pagamento && o.saldo_devedor > 0 && (
                    <p style={{ color: 'var(--accent)', fontSize: 11, margin: '2px 0 0' }}>
                      Venc. {o.data_vencimento_pagamento}
                    </p>
                  )}
                </div>
                <div style={{ textAlign: 'right' }}>
                  <StatusPill status={o.status} />
                  {o.saldo_devedor > 0 && (
                    <p style={{ color: 'var(--danger)', fontSize: 12, margin: '4px 0 0', fontWeight: 700 }}>{formatarMoeda(o.saldo_devedor)}</p>
                  )}
                </div>
              </div>
            ))
          )}
        </div>

        {/* Veículos */}
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Veículos</h3>
            <button
              onClick={() => setAddingVeiculo(v => !v)}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 12 }}
            >
              + Adicionar
            </button>
          </div>

          {veiculos.filter(v => v.ativo).map(v => (
            <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
              <div>
                <p style={{ margin: 0, fontSize: 14, color: 'var(--text)', fontWeight: 600 }}>
                  {v.modelo}{v.ano ? ` ${v.ano}` : ''}
                </p>
                {v.placa && <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>{v.placa}</p>}
              </div>
              <button
                onClick={() => handleRemoverVeiculo(v.id)}
                style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}
              >
                Remover
              </button>
            </div>
          ))}

          {veiculos.filter(v => v.ativo).length === 0 && !addingVeiculo && (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum veículo cadastrado.</p>
          )}

          {addingVeiculo && (
            <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 8 }}>
              <input
                placeholder="Modelo (ex: Honda Civic)"
                value={novoVeiculo.modelo}
                onChange={e => setNovoVeiculo(p => ({ ...p, modelo: e.target.value }))}
                style={{ padding: '7px 10px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none' }}
              />
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                <input
                  placeholder="Ano (ex: 2021)"
                  value={novoVeiculo.ano}
                  onChange={e => setNovoVeiculo(p => ({ ...p, ano: e.target.value }))}
                  style={{ padding: '7px 10px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none' }}
                />
                <input
                  placeholder="Placa (ex: ABC1234)"
                  value={novoVeiculo.placa}
                  onChange={e => setNovoVeiculo(p => ({ ...p, placa: e.target.value }))}
                  style={{ padding: '7px 10px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none' }}
                />
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button
                  onClick={handleAdicionarVeiculo}
                  style={{ flex: 1, padding: '8px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 6, cursor: 'pointer', fontWeight: 700, fontSize: 13 }}
                >
                  Salvar
                </button>
                <button
                  onClick={() => { setAddingVeiculo(false); setNovoVeiculo({ modelo: '', ano: '', placa: '' }) }}
                  style={{ padding: '8px 16px', background: 'transparent', color: 'var(--muted)', border: '1px solid var(--border)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}
                >
                  Cancelar
                </button>
              </div>
            </div>
          )}
        </div>
        </div>
      </div>
    </div>
  )
}
