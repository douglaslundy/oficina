export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      {/* Left panel */}
      <div style={{
        flex: 1,
        background: 'var(--surface)',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        padding: '60px',
        position: 'relative',
        overflow: 'hidden',
      }}>
        <div style={{
          position: 'absolute', inset: 0,
          backgroundImage: 'radial-gradient(circle at 30% 50%, rgba(245,166,35,0.08) 0%, transparent 60%)',
          pointerEvents: 'none',
        }} />
        <div style={{ position: 'relative', zIndex: 1 }}>
          <div style={{
            width: 72, height: 72, borderRadius: 18,
            background: 'var(--accent)', display: 'flex',
            alignItems: 'center', justifyContent: 'center',
            fontSize: 32, marginBottom: 24,
          }}>🔧</div>
          <h1 className="font-display" style={{ fontSize: 36, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            MecânicaPro
          </h1>
          <p style={{ color: 'var(--muted)', marginTop: 8, fontSize: 16 }}>
            Sistema de Gestão para Oficinas Mecânicas
          </p>
          <ul style={{ listStyle: 'none', padding: 0, marginTop: 40, display: 'flex', flexDirection: 'column', gap: 12 }}>
            {[
              'Gestão completa de Ordens de Serviço',
              'Controle de estoque inteligente',
              'Emissão de NF-e / NFS-e',
              'Relatórios financeiros',
              'Multi-usuário com permissões',
            ].map(f => (
              <li key={f} style={{ color: 'var(--muted)', fontSize: 15 }}>
                <span style={{ color: 'var(--accent)', marginRight: 8 }}>✓</span>{f}
              </li>
            ))}
          </ul>
        </div>
      </div>
      {/* Right panel */}
      <div style={{
        width: 480, display: 'flex', alignItems: 'center',
        justifyContent: 'center', padding: 48,
      }}>
        {children}
      </div>
    </div>
  )
}
