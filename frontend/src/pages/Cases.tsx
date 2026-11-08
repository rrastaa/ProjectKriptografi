import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, errMessage } from '../lib/api'
import { useAuth } from '../context/AuthContext'
import type { CaseDetail, CaseSummary, Meta } from '../lib/types'
import { ErrorBox, Modal, StatusBadge, fmtDate } from '../components/ui'

export default function Cases() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [cases, setCases] = useState<CaseSummary[] | null>(null)
  const [meta, setMeta] = useState<Meta | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    try {
      const [c, m] = await Promise.all([api.get<{ cases: CaseSummary[] }>('/cases'), api.get<Meta>('/meta')])
      setCases(c.cases)
      setMeta(m)
    } catch (e) {
      setError(errMessage(e))
    }
  }, [])

  useEffect(() => { void load() }, [load])

  const canCreate = user?.role === 'admin' || user?.role === 'investigator'

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-white">Kasus</h1>
          <p className="text-sm text-slate-400">Judul, deskripsi, dan pelapor tersimpan terenkripsi (AES-256-GCM).</p>
        </div>
        {canCreate && <button className="btn-primary" onClick={() => setCreating(true)}>+ Kasus Baru</button>}
      </div>

      <ErrorBox message={error} />

      <div className="card overflow-x-auto !p-0">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-ink-700">
            <tr>
              <th className="th">No. Kasus</th><th className="th">Judul</th><th className="th">Kategori</th>
              <th className="th">Status</th><th className="th">Berkas</th><th className="th">Dibuat</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink-700">
            {cases === null && <tr><td className="td text-slate-400" colSpan={6}>Memuat…</td></tr>}
            {cases?.length === 0 && <tr><td className="td text-slate-400" colSpan={6}>Belum ada kasus yang dapat Anda akses.</td></tr>}
            {cases?.map((c) => (
              <tr key={c.id} className="hover:bg-ink-700/40">
                <td className="td font-mono text-xs text-slate-300">{c.case_number}</td>
                <td className="td"><Link className="font-medium text-lock-400 hover:underline" to={`/cases/${c.id}`}>{c.title}</Link></td>
                <td className="td text-slate-300">{meta?.categories[c.category] ?? c.category}</td>
                <td className="td"><StatusBadge status={c.status} /></td>
                <td className="td text-slate-300">{c.files_count ?? 0}</td>
                <td className="td text-slate-400">{fmtDate(c.created_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {creating && meta && (
        <NewCase
          meta={meta}
          onClose={() => setCreating(false)}
          onCreated={(c) => { setCreating(false); navigate(`/cases/${c.id}`) }}
        />
      )}
    </div>
  )
}

function NewCase({ meta, onClose, onCreated }: { meta: Meta; onClose: () => void; onCreated: (c: CaseDetail) => void }) {
  const [title, setTitle] = useState('')
  const [category, setCategory] = useState(Object.keys(meta.categories)[0])
  const [description, setDescription] = useState('')
  const [reporter, setReporter] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ case: CaseDetail }>('/cases', { title, category, description, reporter })
      onCreated(res.case)
    } catch (err) {
      setError(errMessage(err))
      setBusy(false)
    }
  }

  return (
    <Modal title="Kasus Baru" onClose={onClose} wide>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className="label" htmlFor="t">Judul kasus</label>
          <input id="t" className="input" required maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="c">Kategori</label>
            <select id="c" className="input" value={category} onChange={(e) => setCategory(e.target.value)}>
              {Object.entries(meta.categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
          <div>
            <label className="label" htmlFor="r">Pelapor (opsional)</label>
            <input id="r" className="input" maxLength={300} value={reporter} onChange={(e) => setReporter(e.target.value)} />
          </div>
        </div>
        <div>
          <label className="label" htmlFor="d">Deskripsi (opsional)</label>
          <textarea id="d" className="input min-h-[120px]" maxLength={10000} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <ErrorBox message={error} />
        <div className="flex justify-end gap-2">
          <button type="button" className="btn-ghost" onClick={onClose}>Batal</button>
          <button className="btn-primary" disabled={busy || !title.trim()}>{busy ? 'Menyimpan…' : 'Buat Kasus'}</button>
        </div>
      </form>
    </Modal>
  )
}
