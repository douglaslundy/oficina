'use client'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

const CATEGORIAS = ['Filtros', 'Óleo/Fluidos', 'Freios', 'Suspensão', 'Elétrica', 'Motor', 'Outros']
const UNIDADES = ['Un', 'L', 'Par', 'Cx', 'Kg', 'm']

const schema = z.object({
  nome:          z.string().min(2, 'Nome do produto é obrigatório'),
  sku:           z.string().optional(),
  codigo_barras: z.string().optional(),
  categoria:     z.string().min(1, 'Selecione uma categoria'),
  unidade:     z.string(),
  qty_atual:   z.number().min(0, 'A quantidade não pode ser negativa').optional(),
  qty_minima:  z.number().min(0, 'O estoque mínimo não pode ser negativo'),
  preco_custo: z.number().min(0, 'O preço de custo não pode ser negativo').optional(),
  preco_venda: z.number().min(0, 'O preço de venda não pode ser negativo').optional(),
})

type FormData = z.infer<typeof schema>

interface ProdutoFormProps {
  initialData?: Partial<FormData> & { id?: string }
  onSuccess?: () => void
}

const iStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}
const lStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

export function ProdutoForm({ initialData, onSuccess }: ProdutoFormProps) {
  const isEdit = !!initialData?.id

  const { register, handleSubmit, setValue, formState: { errors, isSubmitting } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      nome:          initialData?.nome ?? '',
      sku:           initialData?.sku ?? '',
      codigo_barras: initialData?.codigo_barras ?? '',
      categoria:     initialData?.categoria ?? 'Filtros',
      unidade:     initialData?.unidade ?? 'Un',
      qty_atual:   initialData?.qty_atual ?? 0,
      qty_minima:  initialData?.qty_minima ?? 5,
      preco_custo: initialData?.preco_custo ?? undefined,
      preco_venda: initialData?.preco_venda ?? undefined,
    },
  })

  async function onSubmit(data: FormData) {
    try {
      if (isEdit) {
        await api.put(`/produtos/${initialData!.id}`, data)
        toast('Produto atualizado com sucesso!', 'success')
      } else {
        await api.post('/produtos', data)
        toast('Produto cadastrado com sucesso!', 'success')
      }
      onSuccess?.()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao salvar produto.', 'danger')
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>

        {/* Nome */}
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={lStyle}>Nome do produto *</label>
          <input {...register('nome')}
            onChange={e => setValue('nome', e.target.value.toUpperCase(), { shouldValidate: true })}
            style={{ ...iStyle, borderColor: errors.nome ? 'var(--danger)' : 'var(--border)' }}
            placeholder="Ex: FILTRO DE ÓLEO BOSCH" />
          {errors.nome && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.nome.message}</p>}
        </div>

        {/* SKU */}
        <div>
          <label style={lStyle}>SKU / Código</label>
          <input {...register('sku')} style={iStyle} placeholder="Auto-gerado se vazio" className="font-mono" />
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>Deixe em branco para gerar automaticamente.</p>
        </div>

        {/* Código de barras / EAN */}
        <div>
          <label style={lStyle}>Código de barras (EAN)</label>
          <input {...register('codigo_barras')}
            style={{ ...iStyle, borderColor: errors.codigo_barras ? 'var(--danger)' : 'var(--border)' }}
            placeholder="Ex: 7891234567895" className="font-mono" />
          {errors.codigo_barras
            ? <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.codigo_barras.message}</p>
            : <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>Opcional — usado para casar com itens de entrada de NF-e.</p>}
        </div>

        {/* Categoria */}
        <div>
          <label style={lStyle}>Categoria *</label>
          <select {...register('categoria')}
            style={{ ...iStyle, borderColor: errors.categoria ? 'var(--danger)' : 'var(--border)' }}>
            {CATEGORIAS.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
          {errors.categoria && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.categoria.message}</p>}
        </div>

        {/* Unidade */}
        <div>
          <label style={lStyle}>Unidade</label>
          <select {...register('unidade')} style={iStyle}>
            {UNIDADES.map(u => <option key={u} value={u}>{u}</option>)}
          </select>
        </div>

        {/* Linha separadora — Estoque */}
        <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '8px 0 -4px' }}>
          Estoque
        </p>

        {/* Qtd atual — só em criação */}
        {!isEdit && (
          <div>
            <label style={lStyle}>Quantidade inicial</label>
            <input type="number" min={0} {...register('qty_atual', { valueAsNumber: true })}
              style={{ ...iStyle, borderColor: errors.qty_atual ? 'var(--danger)' : 'var(--border)' }} placeholder="0" />
            {errors.qty_atual && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.qty_atual.message}</p>}
          </div>
        )}

        {/* Qtd mínima */}
        <div>
          <label style={lStyle}>Estoque mínimo *</label>
          <input type="number" min={0} {...register('qty_minima', { valueAsNumber: true })}
            style={{ ...iStyle, borderColor: errors.qty_minima ? 'var(--danger)' : 'var(--border)' }} />
          {errors.qty_minima
            ? <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.qty_minima.message}</p>
            : <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>Alerta gerado abaixo deste valor.</p>}
        </div>

        {/* Linha separadora — Preços */}
        <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '8px 0 -4px' }}>
          Preços
        </p>

        <div>
          <label style={lStyle}>Preço de custo (R$)</label>
          <input type="number" min={0} step="0.01" {...register('preco_custo', { valueAsNumber: true })}
            style={{ ...iStyle, borderColor: errors.preco_custo ? 'var(--danger)' : 'var(--border)' }} placeholder="0,00" />
          {errors.preco_custo && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.preco_custo.message}</p>}
        </div>
        <div>
          <label style={lStyle}>Preço de venda (R$)</label>
          <input type="number" min={0} step="0.01" {...register('preco_venda', { valueAsNumber: true })}
            style={{ ...iStyle, borderColor: errors.preco_venda ? 'var(--danger)' : 'var(--border)' }} placeholder="0,00" />
          {errors.preco_venda && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.preco_venda.message}</p>}
        </div>

      </div>

      <div style={{ marginTop: 24, display: 'flex', justifyContent: 'flex-end' }}>
        <button type="submit" disabled={isSubmitting} className="font-display"
          style={{ padding: '10px 28px', background: isSubmitting ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: isSubmitting ? 'not-allowed' : 'pointer' }}>
          {isSubmitting ? 'Salvando...' : isEdit ? 'Atualizar Produto' : 'Cadastrar Produto'}
        </button>
      </div>
    </form>
  )
}
