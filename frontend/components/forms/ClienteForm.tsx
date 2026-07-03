'use client'
import { useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { validarCPFouCNPJ } from '@/lib/validations/br'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

const schema = z.object({
  nome:     z.string().min(2, 'Nome ou Razão Social é obrigatório'),
  cpf_cnpj: z.string().refine(v => validarCPFouCNPJ(v), 'CPF ou CNPJ inválido — verifique os dígitos informados'),
  telefone: z.string().optional(),
  email:    z.string().optional(),
  cep:      z.string().optional(),
  endereco: z.string().optional(),
  bairro:   z.string().optional(),
  cidade:   z.string().optional(),
  uf:       z.string().max(2, 'UF deve ter 2 letras').optional(),
})

type FormData = z.infer<typeof schema>

interface VeiculoRow {
  id?: string
  modelo: string
  ano: string
  placa: string
  chassi: string
  _delete?: boolean
}

interface ClienteFormProps {
  initialData?: Partial<FormData> & { id?: string }
  onSuccess?: () => void
}

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}
const labelStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

export function ClienteForm({ initialData, onSuccess }: ClienteFormProps) {
  const isEdit = !!initialData?.id

  const { register, handleSubmit, setValue, watch, formState: { errors, isSubmitting } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: initialData ?? {},
  })

  const [veiculos, setVeiculos] = useState<VeiculoRow[]>([])

  const cep = watch('cep')
  // O CEP salvo já vem preenchido em modo de edição, o que dispararia o
  // autofill do ViaCEP no primeiro render e sobrescreveria o endereço
  // carregado do banco — pular essa primeira execução.
  const skipNextCepAutoFill = useRef(isEdit)

  // Fetch existing vehicles in edit mode
  useEffect(() => {
    if (!isEdit || !initialData?.id) return
    api.get(`/clientes/${initialData.id}/veiculos`)
      .then(res => {
        const list: Array<{ id: string; modelo: string; ano?: number | null; placa?: string | null; chassi?: string | null; ativo: boolean }> =
          Array.isArray(res.data) ? res.data : (res.data?.data ?? [])
        setVeiculos(list.map(v => ({
          id: v.id,
          modelo: v.modelo,
          ano: v.ano != null ? String(v.ano) : '',
          placa: v.placa ?? '',
          chassi: v.chassi ?? '',
        })))
      })
      .catch(() => {})
  }, [isEdit, initialData?.id])

  // ViaCEP auto-fill
  useEffect(() => {
    const digits = (cep ?? '').replace(/\D/g, '')
    if (digits.length !== 8) return
    if (skipNextCepAutoFill.current) {
      skipNextCepAutoFill.current = false
      return
    }
    fetch(`https://viacep.com.br/ws/${digits}/json/`)
      .then(r => r.json())
      .then((d: { erro?: boolean; logradouro?: string; bairro?: string; localidade?: string; uf?: string }) => {
        if (d.erro) return
        // CEPs rurais/genéricos retornam logradouro/bairro vazios — nunca
        // apagar um valor já preenchido (digitado ou carregado do banco).
        if (d.logradouro) setValue('endereco', d.logradouro.toUpperCase())
        if (d.bairro) setValue('bairro', d.bairro.toUpperCase())
        if (d.localidade) setValue('cidade', d.localidade)
        if (d.uf) setValue('uf', d.uf)
      })
      .catch(() => {})
  }, [cep, setValue])

  function addVeiculo() {
    setVeiculos(prev => [...prev, { modelo: '', ano: '', placa: '', chassi: '' }])
  }

  function removeVeiculo(idx: number) {
    setVeiculos(prev => {
      const row = prev[idx]
      if (row.id) {
        // Mark existing vehicle for deletion
        return prev.map((v, i) => i === idx ? { ...v, _delete: true } : v)
      }
      // Remove new vehicle outright
      return prev.filter((_, i) => i !== idx)
    })
  }

  function updateVeiculo(idx: number, field: keyof Omit<VeiculoRow, 'id' | '_delete'>, value: string) {
    setVeiculos(prev => prev.map((v, i) => i === idx ? { ...v, [field]: value } : v))
  }

  async function syncVeiculos(clienteId: string) {
    const ops: Promise<unknown>[] = []

    for (const v of veiculos) {
      if (v._delete && v.id) {
        ops.push(api.delete(`/veiculos/${v.id}`))
        continue
      }
      if (v._delete) continue // new row marked delete — skip

      const body = {
        modelo: v.modelo,
        ano: v.ano ? parseInt(v.ano, 10) : undefined,
        placa: v.placa || undefined,
        chassi: v.chassi || undefined,
      }

      if (v.id) {
        ops.push(api.put(`/veiculos/${v.id}`, body))
      } else if (v.modelo.trim()) {
        ops.push(api.post(`/clientes/${clienteId}/veiculos`, body))
      }
    }

    await Promise.all(ops)
  }

  async function onSubmit(data: FormData) {
    try {
      let clienteId = initialData?.id ?? ''

      if (isEdit) {
        await api.put(`/clientes/${clienteId}`, data)
      } else {
        const res = await api.post('/clientes', data)
        // Support both { data: { id } } and { id } shapes
        clienteId = (res.data?.data?.id ?? res.data?.id ?? '') as string
      }

      await syncVeiculos(clienteId)

      toast(isEdit ? 'Cliente atualizado com sucesso!' : 'Cliente cadastrado com sucesso!', 'success')
      onSuccess?.()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao salvar cliente.', 'danger')
    }
  }

  const visibleVeiculos = veiculos.filter(v => !v._delete)

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={labelStyle}>Nome / Razão Social *</label>
          <input {...register('nome')} onChange={e => setValue('nome', e.target.value.toUpperCase(), { shouldValidate: true })} style={{ ...inputStyle, borderColor: errors.nome ? 'var(--danger)' : 'var(--border)' }} />
          {errors.nome && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.nome.message}</p>}
        </div>
        <div>
          <label style={labelStyle}>CPF / CNPJ *</label>
          <input {...register('cpf_cnpj')} placeholder="000.000.000-00" style={{ ...inputStyle, borderColor: errors.cpf_cnpj ? 'var(--danger)' : 'var(--border)' }} />
          {errors.cpf_cnpj && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.cpf_cnpj.message}</p>}
        </div>
        <div>
          <label style={labelStyle}>Telefone</label>
          <input {...register('telefone')} style={inputStyle} placeholder="(11) 99999-9999" />
        </div>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={labelStyle}>E-mail</label>
          <input type="email" {...register('email')} style={inputStyle} />
        </div>

        <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '8px 0 -4px' }}>Endereço</p>
        <div>
          <label style={labelStyle}>CEP</label>
          <input {...register('cep')} style={inputStyle} placeholder="00000-000" />
        </div>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={labelStyle}>Endereço</label>
          <input {...register('endereco')} onChange={e => setValue('endereco', e.target.value.toUpperCase())} style={inputStyle} />
        </div>
        <div>
          <label style={labelStyle}>Bairro</label>
          <input {...register('bairro')} onChange={e => setValue('bairro', e.target.value.toUpperCase())} style={inputStyle} />
        </div>
        <div style={{ display: 'flex', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <label style={labelStyle}>Cidade</label>
            <input {...register('cidade')} style={inputStyle} />
          </div>
          <div style={{ width: 60 }}>
            <label style={labelStyle}>UF</label>
            <input {...register('uf')} style={inputStyle} maxLength={2} />
          </div>
        </div>

        {/* Multi-vehicle section */}
        <div style={{ gridColumn: '1 / -1', marginTop: 8 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
            <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: 0 }}>Veículos</p>
            <button
              type="button"
              onClick={addVeiculo}
              style={{ padding: '4px 12px', background: 'rgba(245,166,35,0.12)', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}
            >
              ＋ Adicionar veículo
            </button>
          </div>

          {visibleVeiculos.length === 0 && (
            <p style={{ color: 'var(--muted)', fontSize: 13, padding: '8px 0' }}>Nenhum veículo cadastrado.</p>
          )}

          {veiculos.map((v, idx) => {
            if (v._delete) return null
            return (
              <div
                key={idx}
                style={{ display: 'grid', gridTemplateColumns: '2fr 80px 100px 1fr auto', gap: 8, marginBottom: 8, alignItems: 'end' }}
              >
                <div>
                  {idx === 0 && <label style={labelStyle}>Modelo *</label>}
                  <input
                    value={v.modelo}
                    onChange={e => updateVeiculo(idx, 'modelo', e.target.value.toUpperCase())}
                    placeholder="Ex: HONDA CIVIC"
                    style={inputStyle}
                    required
                  />
                </div>
                <div>
                  {idx === 0 && <label style={labelStyle}>Ano</label>}
                  <input
                    value={v.ano}
                    onChange={e => updateVeiculo(idx, 'ano', e.target.value)}
                    placeholder="2020"
                    type="number"
                    min={1900}
                    max={2100}
                    style={inputStyle}
                  />
                </div>
                <div>
                  {idx === 0 && <label style={labelStyle}>Placa</label>}
                  <input
                    value={v.placa}
                    onChange={e => updateVeiculo(idx, 'placa', e.target.value.toUpperCase())}
                    placeholder="ABC-1234"
                    style={inputStyle}
                  />
                </div>
                <div>
                  {idx === 0 && <label style={labelStyle}>Chassi</label>}
                  <input
                    value={v.chassi}
                    onChange={e => updateVeiculo(idx, 'chassi', e.target.value.toUpperCase())}
                    placeholder="Número do chassi"
                    style={inputStyle}
                  />
                </div>
                <button
                  type="button"
                  onClick={() => removeVeiculo(idx)}
                  style={{ padding: '9px 10px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18, lineHeight: 1 }}
                  title="Remover veículo"
                >
                  ✕
                </button>
              </div>
            )
          })}
        </div>
      </div>

      <div style={{ marginTop: 24, display: 'flex', justifyContent: 'flex-end' }}>
        <button type="submit" disabled={isSubmitting} className="font-display"
          style={{ padding: '10px 28px', background: isSubmitting ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: isSubmitting ? 'not-allowed' : 'pointer' }}>
          {isSubmitting ? 'Salvando...' : isEdit ? 'Atualizar Cliente' : 'Cadastrar Cliente'}
        </button>
      </div>
    </form>
  )
}
