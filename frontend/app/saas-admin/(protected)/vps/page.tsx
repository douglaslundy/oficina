'use client'

import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

interface VpsData {
  memoria: { total_mb: number; usado_mb: number; disponivel_mb: number; percentual: number }
  cpu: { modelo: string; nucleos: number; uso_percentual: number }
  disco: { total_gb: number; usado_gb: number; disponivel_gb: number; percentual: number }
  uptime: { segundos: number; dias: number; horas: number; minutos: number; texto: string }
  loadavg: { '1min': number; '5min': number; '15min': number }
}

function ProgressBar({ percent, color }: { percent: number; color: string }) {
  return (
    <div style={{
      height: 8, borderRadius: 999, background: 'var(--border)',
      overflow: 'hidden', marginTop: 10,
    }}>
      <div style={{
        height: '100%', borderRadius: 999,
        width: `${Math.min(percent, 100)}%`,
        background: color,
        transition: 'width 0.5s ease',
      }} />
    </div>
  )
}

function barColor(percent: number): string {
  if (percent < 60) return 'var(--success)'
  if (percent < 80) return 'var(--accent)'
  return 'var(--danger)'
}

function MetricCard({ title, icon, children }: { title: string; icon: string; children: React.ReactNode }) {
  return (
    <div style={{
      background: 'var(--card)', border: '1px solid var(--border)',
      borderRadius: 10, padding: 20,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
        <span style={{ fontSize: 18 }}>{icon}</span>
        <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--text)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
          {title}
        </span>
      </div>
      {children}
    </div>
  )
}

function StatRow({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
      <span style={{ fontSize: 13, color: 'var(--muted)' }}>{label}</span>
      <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--text)' }}>{value}</span>
    </div>
  )
}

export default function VpsPage() {
  const [data, setData] = useState<VpsData | null>(null)
  const [loading, setLoading] = useState(true)
  const [erro, setErro] = useState(false)
  const [countdown, setCountdown] = useState(30)
  const [atualizadoEm, setAtualizadoEm] = useState<Date | null>(null)

  const carregar = useCallback(() => {
    setErro(false)
    saasApi.get<VpsData>('/saas/vps/status')
      .then(r => {
        setData(r.data)
        setAtualizadoEm(new Date())
        setCountdown(30)
      })
      .catch(() => setErro(true))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    carregar()
    const interval = setInterval(carregar, 30000)
    return () => clearInterval(interval)
  }, [carregar])

  useEffect(() => {
    if (!data) return
    const tick = setInterval(() => setCountdown(c => Math.max(0, c - 1)), 1000)
    return () => clearInterval(tick)
  }, [data])

  if (loading) {
    return <div style={{ padding: '40px 32px', color: 'var(--muted)', fontSize: 14 }}>Coletando métricas da VPS...</div>
  }

  if (erro || !data) {
    return (
      <div style={{ padding: '40px 32px', maxWidth: 800, margin: '0 auto' }}>
        <div style={{
          background: 'rgba(229,57,53,.08)', border: '1px solid rgba(229,57,53,.3)',
          borderRadius: 10, padding: 20, fontSize: 14, color: 'var(--danger)',
        }}>
          ⚠️ Erro ao coletar métricas da VPS.{' '}
          <button onClick={carregar} style={{ color: 'var(--accent)', background: 'none', border: 'none', cursor: 'pointer', fontWeight: 700 }}>
            Tentar novamente
          </button>
        </div>
      </div>
    )
  }

  const { memoria, cpu, disco, uptime, loadavg } = data

  return (
    <div style={{ padding: '28px 32px', maxWidth: 900, margin: '0 auto', color: 'var(--text)' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 24, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Monitor VPS</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Métricas em tempo real do servidor
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          {atualizadoEm && (
            <span style={{ fontSize: 12, color: 'var(--muted)' }}>
              Atualizado às {atualizadoEm.toLocaleTimeString('pt-BR')} · refresh em {countdown}s
            </span>
          )}
          <button onClick={carregar} style={{
            padding: '7px 16px', borderRadius: 7, border: '1px solid var(--accent)',
            background: 'transparent', color: 'var(--accent)', fontSize: 13, fontWeight: 600, cursor: 'pointer',
          }}>⟳ Atualizar</button>
        </div>
      </div>

      {/* Cards Grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 16 }}>

        {/* Memória RAM */}
        <MetricCard title="Memória RAM" icon="🧠">
          <div style={{ fontSize: 28, fontWeight: 800, color: barColor(memoria.percentual), marginBottom: 4 }}>
            {memoria.percentual}%
          </div>
          <ProgressBar percent={memoria.percentual} color={barColor(memoria.percentual)} />
          <div style={{ marginTop: 14 }}>
            <StatRow label="Total" value={`${(memoria.total_mb / 1024).toFixed(1)} GB`} />
            <StatRow label="Em uso" value={`${(memoria.usado_mb / 1024).toFixed(1)} GB`} />
            <StatRow label="Disponível" value={`${(memoria.disponivel_mb / 1024).toFixed(1)} GB`} />
          </div>
        </MetricCard>

        {/* CPU */}
        <MetricCard title="Processador" icon="⚙️">
          <div style={{ fontSize: 28, fontWeight: 800, color: barColor(cpu.uso_percentual), marginBottom: 4 }}>
            {cpu.uso_percentual}%
          </div>
          <ProgressBar percent={cpu.uso_percentual} color={barColor(cpu.uso_percentual)} />
          <div style={{ marginTop: 14 }}>
            <StatRow label="Modelo" value={cpu.modelo.length > 25 ? cpu.modelo.slice(0, 25) + '…' : cpu.modelo} />
            <StatRow label="Núcleos" value={String(cpu.nucleos)} />
            <StatRow label="Load 1m / 5m / 15m" value={`${loadavg['1min']} / ${loadavg['5min']} / ${loadavg['15min']}`} />
          </div>
        </MetricCard>

        {/* Disco */}
        <MetricCard title="Armazenamento" icon="💽">
          <div style={{ fontSize: 28, fontWeight: 800, color: barColor(disco.percentual), marginBottom: 4 }}>
            {disco.percentual}%
          </div>
          <ProgressBar percent={disco.percentual} color={barColor(disco.percentual)} />
          <div style={{ marginTop: 14 }}>
            <StatRow label="Total" value={`${disco.total_gb} GB`} />
            <StatRow label="Em uso" value={`${disco.usado_gb} GB`} />
            <StatRow label="Disponível" value={`${disco.disponivel_gb} GB`} />
          </div>
        </MetricCard>

        {/* Uptime */}
        <MetricCard title="Uptime" icon="⏱️">
          <div style={{ fontSize: 28, fontWeight: 800, color: 'var(--success)', marginBottom: 4 }}>
            {uptime.dias}d {uptime.horas}h
          </div>
          <div style={{ height: 8, borderRadius: 999, background: 'var(--success)', opacity: 0.3, marginTop: 10 }} />
          <div style={{ marginTop: 14 }}>
            <StatRow label="Dias" value={String(uptime.dias)} />
            <StatRow label="Horas" value={String(uptime.horas)} />
            <StatRow label="Minutos" value={String(uptime.minutos)} />
            <StatRow label="Total em horas" value={`${Math.floor(uptime.segundos / 3600)}h`} />
          </div>
        </MetricCard>
      </div>

      {/* Load Average detail */}
      <div style={{
        background: 'var(--card)', border: '1px solid var(--border)',
        borderRadius: 10, padding: 20,
      }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text)', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 16 }}>
          📊 Load Average Detalhado
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
          {[
            { label: 'Último 1 minuto', value: loadavg['1min'], nucleos: cpu.nucleos },
            { label: 'Últimos 5 minutos', value: loadavg['5min'], nucleos: cpu.nucleos },
            { label: 'Últimos 15 minutos', value: loadavg['15min'], nucleos: cpu.nucleos },
          ].map(item => {
            const pct = Math.min((item.value / item.nucleos) * 100, 100)
            return (
              <div key={item.label}>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>{item.label}</div>
                <div style={{ fontSize: 22, fontWeight: 800, color: barColor(pct) }}>{item.value}</div>
                <ProgressBar percent={pct} color={barColor(pct)} />
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
