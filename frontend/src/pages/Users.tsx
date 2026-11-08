import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, errMessage } from '../lib/api'
import { useAuth } from '../context/AuthContext'
import type { Role, User } from '../lib/types'
import { ErrorBox, Modal, RoleBadge } from '../components/ui'

export default function Users() {
  const { user: me } = useAuth()
  const [users, setUsers] = useState<User[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    try { setUsers((await api.get<{ users: User[] }>('/users')).users) } catch (e) { setError(errMessage(e)) }
  }, [])
  useEffect(() => { void load() }, [load])

  async function patch(u: User, body: Partial<{ role: Role; is_active: boolean }>) {
    setError(null)
    try { await api.patch(`/users/${u.id}`, body); await load() } catch (e) { setError(errMessage(e)) }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-white">Pengguna</h1>
          <p className="text-sm text-slate-400">Password disimpan sebagai hash Argon2id.</p>
        </div>
        <button className="btn-primary" onClick={() => setCreating(true)}>+ Pengguna Baru</button>
      </div>
      <ErrorBox message={error} />
      <div className="card overflow-x-auto !p-0">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-ink-700"><tr><th className="th">Nama</th><th className="th">Email</th><th className="th">Peran</th><th className="th">Passkey</th><th className="th">Status</th></tr></thead>
          <tbody className="divide-y divide-ink-700">
            {users?.map((u) => (
              <tr key={u.id}>
                <td className="td font-medium text-white">{u.name}</td>
                <td className="td text-slate-300">{u.email}</td>
                <td className="td">
                  {u.id === me?.id ? <RoleBadge role={u.role} /> : (
                    <select className="input !w-auto !py-1" value={u.role} onChange={(e) => void patch(u, { role: e.target.value as Role })} aria-label={`Peran ${u.name}`}>
                      <option value="admin">admin</option><option value="investigator">investigator</option><option value="viewer">viewer</option>
                    </select>
                  )}
                </td>
                <td className="td text-slate-300">{u.passkeys_count}</td>
                <td className="td">
                  {u.id === me?.id ? <span className="text-xs text-slate-500">Anda</span> : (
                    <button className={u.is_active ? 'btn-danger !px-2.5 !py-1' : 'btn-ghost !px-2.5 !py-1'} onClick={() => void patch(u, { is_active: !u.is_active })}>
                      {u.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {creating && <NewUser onClose={() => setCreating(false)} onCreated={() => { setCreating(false); void load() }} />}
    </div>
  )
}

function NewUser({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'investigator' as Role })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try { await api.post('/users', form); onCreated() } catch (err) { setError(errMessage(err)); setBusy(false) }
  }

  return (
    <Modal title="Pengguna Baru" onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <div><label className="label" htmlFor="un">Nama</label><input id="un" className="input" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
        <div><label className="label" htmlFor="ue">Email</label><input id="ue" type="email" className="input" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
        <div><label className="label" htmlFor="up">Password awal (min. 12 karakter)</label><input id="up" type="password" autoComplete="new-password" className="input" minLength={12} required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></div>
        <div>
          <label className="label" htmlFor="ur">Peran</label>
          <select id="ur" className="input" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as Role })}>
            <option value="admin">admin</option><option value="investigator">investigator</option><option value="viewer">viewer</option>
          </select>
        </div>
        <ErrorBox message={error} />
        <div className="flex justify-end gap-2"><button type="button" className="btn-ghost" onClick={onClose}>Batal</button><button className="btn-primary" disabled={busy}>{busy ? 'Menyimpan…' : 'Buat'}</button></div>
      </form>
    </Modal>
  )
}
