'use client'
import { useState, useEffect } from 'react'
import { useForm, useFieldArray } from 'react-hook-form'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { formatarMoeda } from '@/lib/formatters'

interface OsItem {
  tipo: 'SERVICO' | 'PECA'
  produto_id?: string
  descricao: string
  quantidade: number
  valor_unitario: number
}

interface OsItemLoaded extends OsItem {
  id?: string
  valor_total?: number
}

interface Veiculo {
  id: string
  modelo: string
  ano?: number | null
  placa?: string | null
  chassi?: string | null
  ativo: boolean
}

interface OSFormData {
  cliente_id: string
  veiculo_id?: string
  mecanico_id?: string
  veiculo_descricao?: string
  veiculo_placa?: string
  problema_relatado?: string
  status: string
  forma_pagamento?: string
  prazo_entrega?: string
  valor_pago?: number
  venda_a_prazo?: boolean
  prazo_pagamento_dias?: number
  itens: OsItem[]
}

interface OSFormProps {
  initialData?: {
    id?: string
    cliente_id?: string
    cliente?: { id: string; nome: string; veiculo_placa?: string }
    mecanico_id?: string
    mecanico?: { id: string; nome: string }
    veiculo_descricao?: string
    veiculo_placa?: string
    problema_relatado?: string
    status?: string
    forma_pagamento?: string
    prazo_entrega?: string
    valor_pago?: number
    valor_total?: number
    venda_a_prazo?: boolean
    prazo_pagamento_dias?: number
    data_vencimento_pagamento?: string
    itens?: OsItemLoaded[]
  }
  onSuccess?: (os: Record<string, unknown>) => void
}

const S: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}
const L: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }
const RO: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--surface)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, boxSizing: 'border-box',
  opacity: 0.8,
}

function veiculoLabel(v: Veiculo): string {
  const parts = [v.modelo]
  if (v.ano) parts.push(String(v.ano))
  if (v.placa) parts.push(v.placa)
  return parts.join(' — ')
}

// Rótulo do produto no select, com a quantidade em estoque entre parênteses.
// Ex.: "Correia dentada - (20un)"
function produtoLabel(p: { nome: string; qty_atual: number; unidade?: string }): string {
  const un = (p.unidade ?? 'un').toLowerCase()
  return `${p.nome} - (${p.qty_atual}${un})`
}

export function OSForm({ initialData, onSuccess }: OSFormProps) {
  const isEdit = !!initialData?.id
  const [mecanicos, setMecanicos] = useState<Array<{ id: string; nome: string }>>([])
  const [produtos, setProdutos] = useState<Array<{ id: string; nome: string; qty_atual: number; unidade?: string; preco_venda: number | null }>>([])

  // New mode only
  const [clientes, setClientes] = useState<Array<{ id: string; nome: string; veiculo_modelo?: string; veiculo_ano?: number | null; veiculo_placa?: string }>>([])
  const [veiculos, setVeiculos] = useState<Veiculo[]>([])
  const [veiculoManual, setVeiculoManual] = useState(false)

  const formItens: OsItem[] = (initialData?.itens ?? []).map(i => ({
    tipo: i.tipo,
    produto_id: i.produto_id,
    descricao: i.descricao,
    quantidade: i.quantidade,
    valor_unitario: i.valor_unitario,
  }))

  const { register, handleSubmit, control, watch, setValue, formState: { isSubmitting } } = useForm<OSFormData>({
    defaultValues: {
      status: initialData?.status ?? 'ABERTA',
      cliente_id: initialData?.cliente_id ?? '',
      mecanico_id: initialData?.mecanico_id ?? '',
      veiculo_descricao: initialData?.veiculo_descricao ?? '',
      veiculo_placa: initialData?.veiculo_placa ?? '',
      problema_relatado: initialData?.problema_relatado ?? '',
      forma_pagamento: initialData?.forma_pagamento ?? '',
      prazo_entrega: initialData?.prazo_entrega ?? '',
      valor_pago: initialData?.valor_pago ?? 0,
      venda_a_prazo: initialData?.venda_a_prazo ?? false,
      prazo_pagamento_dias: initialData?.prazo_pagamento_dias,
      itens: formItens,
    },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'itens' })
  const itens = watch('itens')
  const total = itens.reduce((acc, i) => acc + (Number(i.quantidade || 0) * Number(i.valor_unitario || 0)), 0)

  const clienteId = watch('cliente_id')
  const mecanicoId = watch('mecanico_id')
  const veiculo_id = watch('veiculo_id')

  useEffect(() => {
    const requests: Promise<unknown>[] = [
      api.get('/usuarios?role=MECANICO'),
    ]
    if (!isEdit) {
      requests.push(
        api.get('/clientes?per_page=200'),
        api.get('/produtos?per_page=200'),
      )
    } else {
      requests.push(api.get('/produtos?per_page=200'))
    }
    Promise.all(requests).then(results => {
      setMecanicos((results[0] as { data: { data: typeof mecanicos } }).data.data ?? [])
      if (!isEdit) {
        setClientes((results[1] as { data: { data: typeof clientes } }).data.data ?? [])
        setProdutos((results[2] as { data: { data: typeof produtos } }).data.data ?? [])
      } else {
        setProdutos((results[1] as { data: { data: typeof produtos } }).data.data ?? [])
      }
    }).catch(() => {})
  }, [isEdit]) // eslint-disable-line react-hooks/exhaustive-deps

  // Fetch vehicles (new mode only)
  useEffect(() => {
    if (isEdit || !clienteId) {
      setVeiculos([])
      setVeiculoManual(false)
      return
    }

    setValue('veiculo_id', '')
    setValue('veiculo_descricao', '')
    setValue('veiculo_placa', '')
    setVeiculoManual(false)

    const cliente = clientes.find(c => c.id === clienteId)
    const base: Veiculo[] = []
    if (cliente?.veiculo_modelo) {
      base.push({
        id: `__proprio_${clienteId}`,
        modelo: cliente.veiculo_modelo,
        ano: cliente.veiculo_ano ?? null,
        placa: cliente.veiculo_placa ?? null,
        chassi: null,
        ativo: true,
      })
    }

    api.get(`/clientes/${clienteId}/veiculos`)
      .then(res => {
        const list: Veiculo[] = Array.isArray(res.data) ? res.data : (res.data?.data ?? [])
        const ativos = list.filter(v => v.ativo)
        const combined = [...base]
        for (const v of ativos) {
          if (!combined.some(m => m.modelo === v.modelo && m.placa === v.placa)) {
            combined.push(v)
          }
        }
        setVeiculos(combined)
      })
      .catch(() => { setVeiculos(base) })
  }, [clienteId, clientes, setValue, isEdit])

  function handleVeiculoSelect(e: React.ChangeEvent<HTMLSelectElement>) {
    const id = e.target.value
    if (id === '__manual') {
      setValue('veiculo_id', '')
      setValue('veiculo_descricao', '')
      setValue('veiculo_placa', '')
      setVeiculoManual(true)
      return
    }
    setValue('veiculo_id', id)
    setVeiculoManual(false)
    if (!id) {
      setValue('veiculo_descricao', '')
      setValue('veiculo_placa', '')
      return
    }
    const v = veiculos.find(x => x.id === id)
    if (v) {
      setValue('veiculo_descricao', veiculoLabel(v))
      setValue('veiculo_placa', v.placa ?? '')
    }
  }

  async function handleRemoveItem(itemId: string) {
    if (!confirm('Remover este item?')) return
    try {
      await api.delete(`/os/${initialData!.id}/itens/${itemId}`)
      toast('Item removido.', 'success')
      onSuccess?.({})
    } catch {
      toast('Erro ao remover item.', 'danger')
    }
  }

  async function onSubmit(data: OSFormData) {
    try {
      if (isEdit) {
        // Only send editable fields on update
        const payload = {
          status:                data.status,
          mecanico_id:           data.mecanico_id || null,
          problema_relatado:     data.problema_relatado,
          forma_pagamento:       data.forma_pagamento,
          prazo_entrega:         data.prazo_entrega || null,
          valor_pago:            data.valor_pago,
          venda_a_prazo:         data.venda_a_prazo,
          prazo_pagamento_dias:  data.prazo_pagamento_dias,
        }
        const res = await api.put(`/os/${initialData!.id}`, payload)
        toast('OS atualizada!', 'success')
        onSuccess?.(res.data.data as Record<string, unknown>)
      } else {
        const payload = { ...data, valor_total: total }
        const res = await api.post('/os', payload)
        toast('OS criada com sucesso!', 'success')
        onSuccess?.(res.data.data as Record<string, unknown>)
      }
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao salvar OS.', 'danger')
    }
  }

  const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']
  const PAGAMENTO_OPTIONS = ['Dinheiro', 'Cartão de Crédito', 'Cartão de Débito', 'PIX', 'Cheque', 'Boleto']

  const clienteNome = initialData?.cliente?.nome
  const mecanicoNome = initialData?.mecanico?.nome
  const veiculoDisplay = [initialData?.veiculo_descricao, initialData?.veiculo_placa].filter(Boolean).join(' — ')

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 24 }}>

        {/* Cliente */}
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={L}>Cliente</label>
          {isEdit ? (
            <div style={RO}>{clienteNome || '—'}</div>
          ) : (
            <select {...register('cliente_id')} style={S}>
              <option value="">Selecionar cliente...</option>
              {clientes.map(c => (
                <option key={c.id} value={c.id}>{c.nome}</option>
              ))}
            </select>
          )}
        </div>

        {/* Veículo */}
        {isEdit ? (
          veiculoDisplay && (
            <div style={{ gridColumn: '1 / -1' }}>
              <label style={L}>Veículo</label>
              <div style={RO}>{veiculoDisplay}</div>
            </div>
          )
        ) : (
          <>
            {clienteId && !veiculoManual && (
              <div style={{ gridColumn: '1 / -1' }}>
                <label style={L}>Veículo</label>
                <select
                  value={veiculo_id ?? ''}
                  onChange={handleVeiculoSelect}
                  style={S}
                >
                  <option value="">Selecionar veículo...</option>
                  {veiculos.map(v => (
                    <option key={v.id} value={v.id}>{veiculoLabel(v)}</option>
                  ))}
                  <option value="__manual">✏ Informar manualmente</option>
                </select>
              </div>
            )}
            {clienteId && veiculoManual && (
              <>
                <div>
                  <label style={L}>Veículo (descrição)</label>
                  <input {...register('veiculo_descricao')} placeholder="Ex: Honda Civic 2020" style={S} />
                </div>
                <div>
                  <label style={L}>Placa do veículo</label>
                  <input {...register('veiculo_placa')} placeholder="ABC-1234" style={S} />
                </div>
                <div style={{ gridColumn: '1 / -1' }}>
                  <button type="button" onClick={() => setVeiculoManual(false)}
                    style={{ background: 'none', border: 'none', color: 'var(--info)', cursor: 'pointer', fontSize: 13, padding: 0 }}>
                    ← Selecionar da lista de veículos
                  </button>
                </div>
              </>
            )}
          </>
        )}

        {/* Mecânico responsável — controlado para popular corretamente após carga async */}
        <div>
          <label style={L}>Mecânico responsável</label>
          <select
            value={mecanicoId ?? ''}
            onChange={e => setValue('mecanico_id', e.target.value)}
            style={S}
          >
            <option value="">Selecionar mecânico...</option>
            {mecanicos.map(m => <option key={m.id} value={m.id}>{m.nome}</option>)}
          </select>
          {isEdit && mecanicoNome && !mecanicoId && (
            <span style={{ color: 'var(--muted)', fontSize: 12 }}>Atual: {mecanicoNome}</span>
          )}
        </div>

        {/* Status */}
        <div>
          <label style={L}>Status</label>
          <select {...register('status')} style={S}>
            {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          </select>
        </div>

        {/* Problema relatado */}
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={L}>Problema relatado</label>
          {isEdit ? (
            <div style={{ ...RO, whiteSpace: 'pre-wrap', minHeight: 60 }}>
              {initialData?.problema_relatado || '—'}
            </div>
          ) : (
            <textarea {...register('problema_relatado')} rows={3} style={{ ...S, resize: 'vertical' as const }} />
          )}
        </div>

        {/* Prazo de entrega */}
        <div>
          <label style={L}>Prazo de entrega</label>
          <input type="date" {...register('prazo_entrega')} style={S} />
        </div>

        {/* Forma de pagamento */}
        <div>
          <label style={L}>Forma de pagamento</label>
          <select {...register('forma_pagamento')} style={S}>
            <option value="">Selecionar...</option>
            {PAGAMENTO_OPTIONS.map(p => <option key={p} value={p}>{p}</option>)}
          </select>
        </div>

        {/* Venda a prazo */}
        <div style={{ gridColumn: '1 / -1', display: 'flex', alignItems: 'center', gap: 24, padding: '12px 14px', background: 'var(--bg)', borderRadius: 8, border: '1px solid var(--border)' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', userSelect: 'none' as const }}>
            <input type="checkbox" {...register('venda_a_prazo')} style={{ width: 16, height: 16, accentColor: 'var(--accent)', cursor: 'pointer' }} />
            <span style={{ color: 'var(--text)', fontSize: 14, fontWeight: 600 }}>Venda a prazo</span>
            <span style={{ color: 'var(--muted)', fontSize: 12 }}>— pagamento diferido com data de vencimento</span>
          </label>
          {watch('venda_a_prazo') && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginLeft: 'auto' }}>
              <label style={{ ...L, marginBottom: 0, whiteSpace: 'nowrap' as const }}>Prazo de pagamento</label>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="number"
                  min={1}
                  max={365}
                  placeholder="30"
                  {...register('prazo_pagamento_dias', { valueAsNumber: true })}
                  style={{ ...S, width: 80, textAlign: 'center' as const }}
                />
                <span style={{ color: 'var(--muted)', fontSize: 13, whiteSpace: 'nowrap' as const }}>dias após conclusão</span>
              </div>
            </div>
          )}
        </div>

        {/* Data de vencimento (calculada pelo backend) */}
        {initialData?.data_vencimento_pagamento && (
          <div style={{ gridColumn: '1 / -1' }}>
            <div style={{
              display: 'flex', alignItems: 'center', gap: 10,
              padding: '10px 14px', borderRadius: 8,
              background: 'rgba(229,57,53,0.08)', border: '1px solid var(--danger)',
            }}>
              <span style={{ fontSize: 16 }}>📅</span>
              <span style={{ color: 'var(--muted)', fontSize: 13 }}>Vencimento do pagamento:</span>
              <span className="font-mono" style={{ color: 'var(--danger)', fontWeight: 700, fontSize: 14 }}>
                {initialData.data_vencimento_pagamento}
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Serviços e Peças */}
      <div style={{ background: 'var(--bg)', borderRadius: 8, border: '1px solid var(--border)', padding: 16, marginBottom: 24 }}>
        <h4 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 12px' }}>Serviços e Peças</h4>

        {isEdit ? (
          // Edit mode: read-only for CONCLUIDA/CANCELADA, editable otherwise
          <>
            {(initialData?.itens ?? []).length === 0 && ['CONCLUIDA', 'CANCELADA'].includes(watch('status')) ? (
              <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum item registrado.</p>
            ) : (
              <>
                <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1fr auto', gap: 8, paddingBottom: 6, borderBottom: '1px solid var(--border)', marginBottom: 4 }}>
                  {['Descrição', 'Qtd', 'Valor Unit.', 'Total', ''].map(h => (
                    <span key={h} style={{ color: 'var(--muted)', fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>{h}</span>
                  ))}
                </div>
                {(initialData!.itens ?? []).map((item, i) => (
                  <div key={item.id ?? i} style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1fr auto', gap: 8, padding: '8px 0', borderBottom: '1px solid var(--border)', alignItems: 'center' }}>
                    <span style={{ color: 'var(--text)', fontSize: 14 }}>
                      <span style={{ fontSize: 11, color: item.tipo === 'PECA' ? 'var(--accent)' : 'var(--info)', marginRight: 6, fontWeight: 700 }}>
                        {item.tipo === 'PECA' ? 'PEÇA' : 'SERV'}
                      </span>
                      {item.descricao}
                    </span>
                    <span className="font-mono" style={{ color: 'var(--muted)', fontSize: 14 }}>{item.quantidade}</span>
                    <span className="font-mono" style={{ color: 'var(--muted)', fontSize: 14 }}>{formatarMoeda(item.valor_unitario)}</span>
                    <span className="font-mono" style={{ color: 'var(--text)', fontSize: 14, fontWeight: 600 }}>{formatarMoeda(Number(item.quantidade) * Number(item.valor_unitario))}</span>
                    {!['CONCLUIDA', 'CANCELADA'].includes(watch('status')) && item.id ? (
                      <button
                        type="button"
                        onClick={() => handleRemoveItem(item.id!)}
                        style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18, padding: '0 4px', lineHeight: 1 }}
                      >×</button>
                    ) : <span />}
                  </div>
                ))}
                <div style={{ textAlign: 'right', marginTop: 12, paddingTop: 8, borderTop: '1px solid var(--border)' }}>
                  <span style={{ color: 'var(--muted)', fontSize: 14 }}>Total: </span>
                  <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 18, fontWeight: 700 }}>
                    {formatarMoeda(initialData?.valor_total ?? 0)}
                  </span>
                </div>

                {/* Formulário para adicionar novo item (apenas quando editável) */}
                {!['CONCLUIDA', 'CANCELADA'].includes(watch('status')) && initialData?.id && (
                  <NewItemInline osId={initialData.id} produtos={produtos} onAdded={onSuccess} />
                )}
              </>
            )}
          </>
        ) : (
          // Editable items in new mode
          <>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginBottom: 12 }}>
              <button type="button" onClick={() => append({ tipo: 'SERVICO', descricao: '', quantidade: 1, valor_unitario: 0 })}
                style={{ padding: '6px 12px', background: 'rgba(30,136,229,0.15)', border: '1px solid var(--info)', color: 'var(--info)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
                + Serviço
              </button>
              <button type="button" onClick={() => append({ tipo: 'PECA', produto_id: '', descricao: '', quantidade: 1, valor_unitario: 0 })}
                style={{ padding: '6px 12px', background: 'rgba(245,166,35,0.15)', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
                + Peça
              </button>
            </div>

            {fields.length === 0 && (
              <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '16px 0' }}>Nenhum item adicionado.</p>
            )}

            {fields.length > 0 && (
              <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 8, marginBottom: 4, paddingBottom: 6, borderBottom: '1px solid var(--border)' }}>
                <span style={{ color: 'var(--muted)', fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>Descrição / Peça</span>
                <span style={{ color: 'var(--muted)', fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>Quantidade</span>
                <span style={{ color: 'var(--muted)', fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>Valor Unit. (R$)</span>
                <span />
              </div>
            )}

            {fields.map((field, idx) => {
              const tipo = watch(`itens.${idx}.tipo`)
              return (
                <div key={field.id} style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 8, marginBottom: 8, padding: 8, background: 'var(--card)', borderRadius: 8 }}>
                  {tipo === 'PECA' ? (
                    <select
                      {...register(`itens.${idx}.produto_id`)}
                      style={S}
                      onChange={e => {
                        setValue(`itens.${idx}.produto_id`, e.target.value)
                        const produto = produtos.find(p => p.id === e.target.value)
                        if (produto) {
                          setValue(`itens.${idx}.descricao`, produto.nome)
                          if (produto.preco_venda != null) {
                            setValue(`itens.${idx}.valor_unitario`, produto.preco_venda)
                          }
                        }
                      }}
                    >
                      <option value="">Selecionar peça...</option>
                      {produtos.map(p => (
                        <option key={p.id} value={p.id}>{produtoLabel(p)}</option>
                      ))}
                    </select>
                  ) : (
                    <input {...register(`itens.${idx}.descricao`)} placeholder="Descrição do serviço" style={S} />
                  )}
                  <input type="number" step="0.01" min="0.01" {...register(`itens.${idx}.quantidade`)} placeholder="Qtd" style={S} />
                  <input type="number" step="0.01" min="0" {...register(`itens.${idx}.valor_unitario`)} placeholder="R$ unit." style={S} />
                  <button type="button" onClick={() => remove(idx)}
                    style={{ padding: '0 12px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18 }}>
                    ×
                  </button>
                </div>
              )
            })}

            {fields.length > 0 && (
              <div style={{ textAlign: 'right', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)' }}>
                <span style={{ color: 'var(--muted)', fontSize: 14 }}>Total: </span>
                <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 18, fontWeight: 700 }}>
                  {formatarMoeda(total)}
                </span>
              </div>
            )}
          </>
        )}
      </div>

      {/* Valor pago (edit only) */}
      {isEdit && (
        <div style={{ marginBottom: 24 }}>
          <label style={L}>Valor pago (R$)</label>
          <input type="number" step="0.01" min="0" {...register('valor_pago')} style={{ ...S, width: 200 }} />
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <button type="submit" disabled={isSubmitting} className="font-display"
          style={{ padding: '10px 28px', background: isSubmitting ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: isSubmitting ? 'not-allowed' : 'pointer' }}>
          {isSubmitting ? 'Salvando...' : isEdit ? 'Atualizar OS' : 'Criar OS'}
        </button>
      </div>
    </form>
  )
}

function NewItemInline({ osId, produtos, onAdded }: {
  osId: string
  produtos: Array<{ id: string; nome: string; qty_atual: number; unidade?: string; preco_venda: number | null }>
  onAdded?: (data: Record<string, unknown>) => void
}) {
  const [tipo, setTipo] = useState<'SERVICO' | 'PECA'>('SERVICO')
  const [produtoId, setProdutoId] = useState('')
  const [descricao, setDescricao] = useState('')
  const [quantidade, setQuantidade] = useState(1)
  const [valorUnitario, setValorUnitario] = useState(0)
  const [loading, setLoading] = useState(false)

  function handleProdutoSelect(id: string) {
    setProdutoId(id)
    const p = produtos.find(x => x.id === id)
    if (p) {
      setDescricao(p.nome)
      setValorUnitario(p.preco_venda ?? 0)
    }
  }

  async function handleAdd() {
    if (!descricao || quantidade <= 0) return
    setLoading(true)
    try {
      await api.post(`/os/${osId}/itens`, {
        tipo,
        produto_id: produtoId || null,
        descricao,
        quantidade,
        valor_unitario: valorUnitario,
      })
      toast('Item adicionado.', 'success')
      setDescricao('')
      setProdutoId('')
      setQuantidade(1)
      setValorUnitario(0)
      onAdded?.({})
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao adicionar item.', 'danger')
    } finally {
      setLoading(false)
    }
  }

  const SI: React.CSSProperties = {
    padding: '7px 10px', borderRadius: 6, background: 'var(--bg)',
    border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
  }

  return (
    <div style={{ marginTop: 12, padding: 12, borderRadius: 8, border: '1px dashed var(--border)', background: 'var(--surface)' }}>
      <p style={{ color: 'var(--muted)', fontSize: 12, marginBottom: 8, fontWeight: 600 }}>+ Adicionar item</p>
      <div style={{ display: 'grid', gridTemplateColumns: '100px 1fr', gap: 8, marginBottom: 8 }}>
        <select value={tipo} onChange={e => setTipo(e.target.value as 'SERVICO' | 'PECA')} style={SI}>
          <option value="SERVICO">Serviço</option>
          <option value="PECA">Peça</option>
        </select>
        {tipo === 'PECA' ? (
          <select value={produtoId} onChange={e => handleProdutoSelect(e.target.value)} style={SI}>
            <option value="">Selecionar peça...</option>
            {produtos.map(p => <option key={p.id} value={p.id}>{produtoLabel(p)}</option>)}
          </select>
        ) : (
          <input value={descricao} onChange={e => setDescricao(e.target.value)}
            placeholder="Descrição do serviço" style={SI} />
        )}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '80px 130px 1fr', gap: 8, alignItems: 'center' }}>
        <input type="number" value={quantidade} min={0.01} step={0.01}
          onChange={e => setQuantidade(Number(e.target.value))}
          placeholder="Qtd" style={SI} />
        <input type="number" value={valorUnitario} min={0} step={0.01}
          onChange={e => setValorUnitario(Number(e.target.value))}
          placeholder="Valor unit. (R$)" style={SI} />
        <button type="button" onClick={handleAdd} disabled={loading}
          style={{ padding: '7px 16px', background: 'var(--accent)', color: '#000', borderRadius: 6, border: 'none', cursor: loading ? 'not-allowed' : 'pointer', fontSize: 13, fontWeight: 700 }}>
          {loading ? '...' : 'Adicionar'}
        </button>
      </div>
    </div>
  )
}
