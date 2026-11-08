import { useEffect, type ReactNode } from 'react'

export function LockIcon({ className = 'h-5 w-5' }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <rect x="4" y="11" width="16" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
  )
}

export function Modal({ title, onClose, children, wide = false }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 p-4 sm:items-center" onMouseDown={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`card my-8 w-full ${wide ? 'max-w-3xl' : 'max-w-md'} shadow-2xl`}
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between gap-4">
          <h2 className="text-lg font-semibold text-white">{title}</h2>
          <button className="text-slate-400 hover:text-white" onClick={onClose} aria-label="Tutup">✕</button>
        </div>
        {children}
      </div>
    </div>
  )
}

export function ErrorBox({ message }: { message: string | null }) {
  if (!message) return null
  return <div role="alert" className="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">{message}</div>
}

export function InfoBox({ children }: { children: ReactNode }) {
  return <div className="rounded-lg border border-lock-500/30 bg-lock-500/10 px-3 py-2 text-sm text-lock-400">{children}</div>
}

const STATUS_STYLE: Record<string, string> = {
  open: 'bg-sky-500/15 text-sky-300',
  investigating: 'bg-amber-500/15 text-amber-300',
  closed: 'bg-slate-500/20 text-slate-300',
}
const STATUS_LABEL: Record<string, string> = { open: 'Terbuka', investigating: 'Diselidiki', closed: 'Ditutup' }

export function StatusBadge({ status }: { status: string }) {
  return <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLE[status] ?? STATUS_STYLE.closed}`}>{STATUS_LABEL[status] ?? status}</span>
}

export function RoleBadge({ role }: { role: string }) {
  const style = role === 'admin' ? 'bg-fuchsia-500/15 text-fuchsia-300' : role === 'owner' ? 'bg-lock-500/15 text-lock-400' : role === 'investigator' ? 'bg-sky-500/15 text-sky-300' : 'bg-slate-500/20 text-slate-300'
  return <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${style}`}>{role}</span>
}

export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

export function fmtSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
