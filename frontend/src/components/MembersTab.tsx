import { useEffect, useState } from 'react'
import { api, errMessage } from '../lib/api'
import type { CaseDetail } from '../lib/types'
import { ErrorBox, RoleBadge } from './ui'

interface DirUser { id: number; name: string; email: string; role: string }

export default function MembersTab({ c, onChange }: { c: CaseDetail; onChange: (c: CaseDetail) => void }) {
  const [directory, setDirectory] = useState<DirUser[]>([])
  const [userId, setUserId] = useState('')
  const [role, setRole] = useState<'investigator' | 'viewer'>('viewer')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!c.me.can_manage) return
    api.get<{ users: DirUser[] }>('/directory').then((r) => setDirectory(r.users)).catch(() => setDirectory([]))
  }, [c.me.can_manage])

  const memberIds = new Set(c.members.map((m) => m.id))
  const candidates = directory.filter((u) => !memberIds.has(u.id))

  async function add() {
    setError(null)
    try {
      onChange((await api.post<{ case: CaseDetail }>(`/cases/${c.id}/members`, { user_id: Number(userId), role })).case)
      setUserId('')
    } catch (e) { setError(errMessage(e)) }
  }

  async function remove(id: number) {
    setError(null)
    try { onChange((await api.del<{ case: CaseDetail }>(`/cases/${c.id}/members/${id}`)).case) } catch (e) { setError(errMessage(e)) }
  }

  return (
    <div className="space-y-4">
      <ErrorBox message={error} />
      <div className="card divide-y divide-ink-700 !p-0">
        {c.members.map((m) => (
          <div key={m.id} className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
              <div className="truncate text-sm font-medium text-white">{m.name}</div>
              <div className="truncate text-xs text-slate-400">{m.email}</div>
            </div>
            <RoleBadge role={m.role} />
            {c.me.can_manage && m.role !== 'owner' && <button className="btn-danger !px-2.5 !py-1.5" onClick={() => void remove(m.id)}>Hapus</button>}
          </div>
        ))}
      </div>

      {c.me.can_manage && (
        <div className="card space-y-3">
          <h3 className="text-sm font-semibold text-white">Tambah anggota</h3>
          <div className="grid gap-3 sm:grid-cols-[1fr_180px_auto]">
            <select className="input" value={userId} onChange={(e) => setUserId(e.target.value)} aria-label="Pengguna">
              <option value="">Pilih pengguna…</option>
              {candidates.map((u) => <option key={u.id} value={u.id}>{u.name} — {u.email}</option>)}
            </select>
            <select className="input" value={role} onChange={(e) => setRole(e.target.value as 'investigator' | 'viewer')} aria-label="Peran di kasus">
              <option value="viewer">viewer (hanya baca)</option>
              <option value="investigator">investigator (tambah berkas/pesan)</option>
            </select>
            <button className="btn-primary" disabled={!userId} onClick={() => void add()}>Tambah</button>
          </div>
          <p className="text-xs text-slate-500">Anggota baru belum bisa membuka berkas apa pun sampai diberi izin per berkas (tombol “Izin” di tab Berkas).</p>
        </div>
      )}
    </div>
  )
}
