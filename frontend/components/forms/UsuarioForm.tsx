'use client'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { validarCPF } from '@/lib/validations/br'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

const ROLES = ['ADMIN', 'MECANICO', 'ATENDENTE', 'FINANCEIRO'] as const

const schema = z.object({
  nome:     z.string().min(2, 'Nome completo é obrigatório'),
  email:    z.string().email('Informe um e-mail válido (ex: joao@oficina.com.br)'),
  cpf:      z.string().refine(v => validarCPF(v.replace(/\D/g, '')), 'CPF inválido — verifique os dígitos'),
  telefone: z.string().optional(),
  role:     z.enum(ROLES),
  status:   z.enum(['ATIVO', 'INATIVO']),
  senha:    z.string().optional(),
})

type FormData = z.infer<typeof schema>

interface UsuarioFormProps {
  initialData?: Partial<FormData> & { id?: string }
  onSuccess?: () => void
}

const iStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}
const lStyle: React.CSSProperties = {
  color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4,
}

const ROLE_LABELS: Record<string, string> = {
  ADMIN:      'Administrador',
  MECANICO:   'Mecânico',
  ATENDENTE:  'Atendente',
  FINANCEIRO: 'Financeiro',
}

export function UsuarioForm({ initialData, onSuccess }: UsuarioFormProps) {
  const isEdit = !!initialData?.id
  const [showSenha, setShowSenha] = useState(false)

  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      nome:     initialData?.nome ?? '',
      email:    initialData?.email ?? '',
      cpf:      initialData?.cpf ?? '',
      telefone: initialData?.telefone ?? '',
      role:     (initialData?.role as typeof ROLES[number]) ?? 'ATENDENTE',
      status:   (initialData?.status as 'ATIVO' | 'INATIVO') ?? 'ATIVO',
      senha:    '',
    },
  })

  async function onSubmit(data: FormData) {
    const payload = { ...data }
    if (isEdit && !payload.senha) delete payload.senha

    try {
      if (isEdit) {
        await api.put(`/usuarios/${initialData!.id}`, payload)
        toast('Usuário atualizado com sucesso!', 'success')
      } else {
        if (!payload.senha || payload.senha.length < 8) {
          toast('Informe uma senha com no mínimo 8 caracteres para criar o usuário.', 'danger')
          return
        }
        await api.post('/usuarios', payload)
        toast('Usuário cadastrado com sucesso!', 'success')
      }
      onSuccess?.()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao salvar usuário.', 'danger')
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>

        {/* Nome */}
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={lStyle}>Nome completo *</label>
          <input
            {...register('nome')}
            placeholder="João da Silva"
            style={{ ...iStyle, borderColor: errors.nome ? 'var(--danger)' : 'var(--border)' }}
          />
          {errors.nome && (
            <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.nome.message}</p>
          )}
        </div>

        {/* E-mail */}
        <div>
          <label style={lStyle}>E-mail *</label>
          <input
            type="email"
            {...register('email')}
            placeholder="joao@oficina.com.br"
            style={{ ...iStyle, borderColor: errors.email ? 'var(--danger)' : 'var(--border)' }}
          />
          {errors.email && (
            <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.email.message}</p>
          )}
        </div>

        {/* CPF */}
        <div>
          <label style={lStyle}>CPF *</label>
          <input
            {...register('cpf')}
            placeholder="000.000.000-00"
            className="font-mono"
            style={{ ...iStyle, borderColor: errors.cpf ? 'var(--danger)' : 'var(--border)' }}
          />
          {errors.cpf && (
            <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.cpf.message}</p>
          )}
        </div>

        {/* Telefone */}
        <div>
          <label style={lStyle}>Telefone</label>
          <input {...register('telefone')} style={iStyle} placeholder="(11) 99999-9999" />
        </div>

        {/* Perfil de acesso */}
        <div>
          <label style={lStyle}>Perfil de acesso *</label>
          <select {...register('role')} style={iStyle}>
            {ROLES.map(r => (
              <option key={r} value={r}>{ROLE_LABELS[r]}</option>
            ))}
          </select>
        </div>

        {/* Status — só em edição */}
        {isEdit && (
          <div>
            <label style={lStyle}>Status</label>
            <select {...register('status')} style={iStyle}>
              <option value="ATIVO">Ativo</option>
              <option value="INATIVO">Inativo</option>
            </select>
          </div>
        )}

        {/* Senha */}
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={lStyle}>
            {isEdit ? 'Nova senha (deixe em branco para não alterar)' : 'Senha *'}
          </label>
          <div style={{ position: 'relative' }}>
            <input
              type={showSenha ? 'text' : 'password'}
              {...register('senha')}
              placeholder={isEdit ? '••••••••' : 'Mínimo 8 caracteres, 1 maiúscula, 1 número'}
              style={{ ...iStyle, paddingRight: 44 }}
            />
            <button
              type="button"
              onClick={() => setShowSenha(s => !s)}
              style={{
                position: 'absolute', right: 12, top: '50%',
                transform: 'translateY(-50%)', background: 'none',
                border: 'none', color: 'var(--muted)', cursor: 'pointer',
              }}
            >
              {showSenha ? '🙈' : '👁'}
            </button>
          </div>
          {!isEdit && (
            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
              Mínimo 8 caracteres, pelo menos 1 letra maiúscula e 1 número.
            </p>
          )}
        </div>

      </div>

      <div style={{ marginTop: 24, display: 'flex', justifyContent: 'flex-end' }}>
        <button
          type="submit"
          disabled={isSubmitting}
          className="font-display"
          style={{
            padding: '10px 28px',
            background: isSubmitting ? 'var(--muted)' : 'var(--accent)',
            color: '#000', borderRadius: 8, border: 'none',
            fontWeight: 800, fontSize: 16,
            cursor: isSubmitting ? 'not-allowed' : 'pointer',
          }}
        >
          {isSubmitting ? 'Salvando...' : isEdit ? 'Atualizar Usuário' : 'Cadastrar Usuário'}
        </button>
      </div>
    </form>
  )
}
