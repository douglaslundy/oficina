'use client'

import { useState } from 'react'
import Link from 'next/link'

interface ErrorProps {
  error: Error & { digest?: string }
  reset: () => void
}

export default function DashboardError({ error, reset }: ErrorProps) {
  const [linkHovered, setLinkHovered] = useState(false)

  return (
    <div
      style={{
        minHeight: '100vh',
        background: 'var(--bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '24px',
      }}
    >
      <div
        style={{
          background: 'var(--card)',
          border: '1px solid var(--border)',
          borderRadius: '16px',
          padding: '48px 40px',
          maxWidth: '480px',
          width: '100%',
          textAlign: 'center',
        }}
      >
        {/* Error icon */}
        <div
          style={{
            fontSize: '48px',
            lineHeight: 1,
            marginBottom: '24px',
            color: 'var(--danger)',
          }}
        >
          ⚠
        </div>

        {/* Title */}
        <h1
          className="font-display"
          style={{
            fontSize: '28px',
            fontWeight: 700,
            color: 'var(--text)',
            margin: '0 0 12px',
            letterSpacing: '-0.01em',
          }}
        >
          Algo deu errado
        </h1>

        {/* Error message */}
        <p
          style={{
            color: 'var(--muted)',
            fontSize: '14px',
            lineHeight: 1.6,
            margin: '0 0 8px',
          }}
        >
          {error.message || 'Ocorreu um erro inesperado ao carregar esta página.'}
        </p>

        {/* Digest */}
        {error.digest && (
          <p
            className="font-mono"
            style={{
              color: 'var(--muted)',
              fontSize: '11px',
              margin: '0 0 32px',
              opacity: 0.7,
            }}
          >
            código: {error.digest}
          </p>
        )}

        {!error.digest && <div style={{ marginBottom: '32px' }} />}

        {/* Actions */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          <button
            onClick={reset}
            style={{
              background: 'var(--accent)',
              color: '#0e0f11',
              border: 'none',
              borderRadius: '8px',
              padding: '12px 24px',
              fontFamily: "'Barlow Condensed', sans-serif",
              fontSize: '17px',
              fontWeight: 800,
              cursor: 'pointer',
              width: '100%',
              letterSpacing: '0.02em',
            }}
          >
            Tentar novamente
          </button>

          <Link
            href="/"
            style={{
              color: linkHovered ? 'var(--text)' : 'var(--muted)',
              fontSize: '14px',
              textDecoration: 'none',
              padding: '8px',
              display: 'block',
              transition: 'color 0.15s',
            }}
            onMouseEnter={() => setLinkHovered(true)}
            onMouseLeave={() => setLinkHovered(false)}
          >
            ← Voltar ao início
          </Link>
        </div>
      </div>
    </div>
  )
}
