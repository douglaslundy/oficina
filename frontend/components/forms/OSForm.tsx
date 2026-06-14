'use client'
import { useState, useEffect, useRef } from 'react'
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
  itens: OsItem[]
}

interface OSFormProps {
  initialData?: Partial<OSFormData> & { id?: string }
  onSuccess?: (os: Record<string, unknown>) => void
}

const S: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}
const L: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

function veiculoLabel(v: Veiculo): string {
  const parts = [v.modelo]
  if (v.ano) parts.push(String(v.ano))
  if (v.placa) parts.push(v.placa)
  return parts.join(' — ')
}

export function OSForm({ initialData, onSuccess }: OSFormProps) {
  const isEdit = !!initialData?.id
  const [clientes, setClientes] = useState<Array<{ id: string; nome: string; veiculo_modelo?: string; veiculo_ano?: number | null; veiculo_placa?: string }>>([])
  const [mecanicos, setMecanicos] = useState<Array<{ id: string; nome: string }>>([])
  const [produtos, setProdutos] = useState<Array<{ id: string; nome: string; qty_atual: number; preco_venda: number | null }>>([])
  const [veiculos, setVeiculos] = useState<Veiculo[]>([])
  const [veiculoManual, setVeiculoManual] = useState(false)

  const { register, handleSubmit, control, watch, setValue, formState: { isSubmitting } } = useForm<OSFormData>({
    defaultValues: { status: 'ABERTA', itens: [], ...initialData },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'itens' })
  const itens = watch('itens')
  const total = itens.reduce((acc, i) => acc + (Number(i.quantidade || 0) * Number(i.valor_unitario || 0)), 0)

  const clienteId = watch('cliente_id')
  // Track the last clienteId we acted on so clientes list loading doesn't re-clear veiculo fields
  const lastClienteRef = useRef<string>(initialData?.cliente_id ?? '')

  // Load reference data on mount
  useEffect(() => {
    Promise.all([
      api.get('/clientes?per_page=200'),
      api.get('/usuarios?role=MECANICO'),
      api.get('/produtos?per_page=200'),
    ]).then(([c, u, p]) => {
      setClientes(c.data.data ?? [])
      setMecanicos(u.data.data ?? [])
      setProdutos(p.data.data ?? [])
    }).catch(() => {})
  }, [])

  // Fetch vehicles when client changes
  useEffect(() => {
    if (!clienteId) {
      setVeiculos([])
      setVeiculoManual(false)
      lastClienteRef.current = ''
      return
    }

    const clienteChanged = lastClienteRef.current !== clienteId
    lastClienteRef.current = clienteId

    // Only clear veiculo selection when the user actively picked a different client
    if (clienteChanged && !isEdit) {
      setValue('veiculo_id', '')
      setValue('veiculo_descricao', '')
      setValue('veiculo_placa', '')
      setVeiculoManual(false)
    }

    // Build base list from client's own vehicle fields
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

    // Fetch additional vehicles from the veiculos table
    api.get(`/clientes/${clienteId}/veiculos`)
      .then(res => {
        const list: Veiculo[] = Array.isArray(res.data) ? res.data : (res.data?.data ?? [])
        const ativos = list.filter(v => v.ativo)
        // Merge without duplicating same modelo+placa
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

  async function onSubmit(data: OSFormData) {
    try {
      const payload = { ...data, valor_total: total }
      if (isEdit) {
        const res = await api.put(`/os/${initialData!.id}`, payload)
        toast('OS atualizada!', 'success')
        onSuccess?.(res.data.data as Record<string, unknown>)
      } else {
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

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 24 }}>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={L}>Cliente *</label>
          <select {...register('cliente_id')} style={S}>
            <option value="">Selecionar cliente...</option>
            {clientes.map(c => (
              <option key={c.id} value={c.id}>{c.nome}</option>
            ))}
          </select>
        </div>

        {/* Vehicle section — select quando cliente selecionado, texto quando "manual" */}
        {clienteId && !veiculoManual && (
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={L}>Veículo</label>
            <select
              value={watch('veiculo_id') ?? ''}
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

        <div>
          <label style={L}>Mecânico responsável</label>
          <select {...register('mecanico_id')} style={S}>
            <option value="">Selecionar mecânico...</option>
            {mecanicos.map(m => <option key={m.id} value={m.id}>{m.nome}</option>)}
          </select>
        </div>
        <div>
          <label style={L}>Status</label>
          <select {...register('status')} style={S}>
            {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          </select>
        </div>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={L}>Problema relatado</label>
          <textarea {...register('problema_relatado')} rows={3} style={{ ...S, resize: 'vertical' as const }} />
        </div>
        <div>
          <label style={L}>Prazo de entrega</label>
          <input type="date" {...register('prazo_entrega')} style={S} />
        </div>
        <div>
          <label style={L}>Forma de pagamento</label>
          <select {...register('forma_pagamento')} style={S}>
            <option value="">Selecionar...</option>
            {PAGAMENTO_OPTIONS.map(p => <option key={p} value={p}>{p}</option>)}
          </select>
        </div>
      </div>

      {/* Itens */}
      <div style={{ background: 'var(--bg)', borderRadius: 8, border: '1px solid var(--border)', padding: 16, marginBottom: 24 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <h4 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Serviços e Peças</h4>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" onClick={() => append({ tipo: 'SERVICO', descricao: '', quantidade: 1, valor_unitario: 0 })}
              style={{ padding: '6px 12px', background: 'rgba(30,136,229,0.15)', border: '1px solid var(--info)', color: 'var(--info)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
              + Serviço
            </button>
            <button type="button" onClick={() => append({ tipo: 'PECA', produto_id: '', descricao: '', quantidade: 1, valor_unitario: 0 })}
              style={{ padding: '6px 12px', background: 'rgba(245,166,35,0.15)', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
              + Peça
            </button>
          </div>
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
                    <option key={p.id} value={p.id}>{p.nome} (est: {p.qty_atual})</option>
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
      </div>

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
