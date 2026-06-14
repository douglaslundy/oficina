'use client'
import { useState } from 'react'

interface PasswordInputProps {
  id: string
  value: string
  placeholder?: string
  showStrength?: boolean
  error?: string
  onChange: (value: string) => void
}

function PasswordStrength({ password }: { password: string }) {
  if (!password) return null
  const checks = [
    password.length >= 8,
    /[A-Z]/.test(password),
    /[0-9]/.test(password),
    /[^A-Za-z0-9]/.test(password),
  ]
  const score = checks.filter(Boolean).length
  const colors = ['var(--danger)', 'var(--danger)', 'var(--accent)', 'var(--accent)', 'var(--success)']
  const labels = ['', 'Fraca', 'Fraca', 'Média', 'Forte']
  return (
    <div style={{ marginTop: 8 }}>
      <div style={{ display: 'flex', gap: 4 }}>
        {[1, 2, 3, 4].map(i => (
          <div
            key={i}
            style={{ height: 4, flex: 1, borderRadius: 2, background: i <= score ? colors[score] : 'var(--border)' }}
          />
        ))}
      </div>
      <p style={{ color: colors[score], fontSize: 12, marginTop: 4 }}>{labels[score]}</p>
    </div>
  )
}

export function PasswordInput({ id, value, placeholder = '••••••••', showStrength = false, error, onChange }: PasswordInputProps) {
  const [show, setShow] = useState(false)

  const inputStyle: React.CSSProperties = {
    width: '100%',
    padding: '10px 44px 10px 14px',
    borderRadius: 8,
    background: 'var(--card)',
    border: `1px solid ${error ? 'var(--danger)' : 'var(--border)'}`,
    color: 'var(--text)',
    fontSize: 15,
    outline: 'none',
    boxSizing: 'border-box',
  }

  return (
    <div>
      <div style={{ position: 'relative' }}>
        <input
          id={id}
          type={show ? 'text' : 'password'}
          value={value}
          placeholder={placeholder}
          onChange={e => onChange(e.target.value)}
          style={inputStyle}
        />
        <button
          type="button"
          onClick={() => setShow(s => !s)}
          style={{
            position: 'absolute',
            right: 12,
            top: '50%',
            transform: 'translateY(-50%)',
            background: 'none',
            border: 'none',
            color: 'var(--muted)',
            cursor: 'pointer',
            fontSize: 16,
            lineHeight: 1,
          }}
          tabIndex={-1}
        >
          {show ? '🙈' : '👁'}
        </button>
      </div>
      {showStrength && <PasswordStrength password={value} />}
      {error && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{error}</p>}
    </div>
  )
}
