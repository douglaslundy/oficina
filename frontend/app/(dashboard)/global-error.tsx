'use client'

import Link from 'next/link'

interface GlobalErrorProps {
  error: Error & { digest?: string }
  reset: () => void
}

export default function GlobalError({ error, reset }: GlobalErrorProps) {
  return (
    <html lang="pt-BR">
      <head>
        <title>Erro — MecânicaPro</title>
        <link
          rel="preconnect"
          href="https://fonts.googleapis.com"
        />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&family=Barlow:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap"
        />
        <style>{`
          *, *::before, *::after { box-sizing: border-box; }
          html, body {
            margin: 0;
            padding: 0;
            background: #0e0f11;
            color: #e8eaf0;
            font-family: 'Barlow', sans-serif;
            min-height: 100vh;
          }
          .font-display { font-family: 'Barlow Condensed', sans-serif; }
          .font-mono { font-family: 'JetBrains Mono', monospace; }
        `}</style>
      </head>
      <body>
        <div
          style={{
            minHeight: '100vh',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '24px',
          }}
        >
          <div
            style={{
              background: '#1c1e21',
              border: '1px solid #2a2d33',
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
                color: '#e53935',
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
                color: '#e8eaf0',
                margin: '0 0 12px',
                letterSpacing: '-0.01em',
              }}
            >
              Erro crítico da aplicação
            </h1>

            {/* Message */}
            <p
              style={{
                color: '#7a8090',
                fontSize: '14px',
                lineHeight: 1.6,
                margin: '0 0 8px',
              }}
            >
              {error.message || 'Ocorreu um erro grave e a aplicação não pôde continuar.'}
            </p>

            {/* Digest */}
            {error.digest && (
              <p
                className="font-mono"
                style={{
                  color: '#7a8090',
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
                  background: '#f5a623',
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
                href="/login"
                style={{
                  color: '#7a8090',
                  fontSize: '14px',
                  textDecoration: 'none',
                  padding: '8px',
                  display: 'block',
                }}
              >
                ← Voltar ao login
              </Link>
            </div>
          </div>
        </div>
      </body>
    </html>
  )
}
