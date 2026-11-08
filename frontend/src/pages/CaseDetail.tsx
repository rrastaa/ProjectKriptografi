import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errMessage } from '../lib/api'
import type { AuditRow, CaseDetail, Meta } from '../lib/types'
import { ErrorBox, StatusBadge, fmtDate } from '../components/ui'
import FilesTab from '../components/FilesTab'
import MessagesTab from '../components/MessagesTab'
import MembersTab from '../components/MembersTab'
import AuditTable from '../components/AuditTable'

type Tab = 'files' | 'messages' | 'members' | 'audit'

export default function CaseDetailPage() {
  const { id } = useParams()
  const [c, setCase] = useState<CaseDetail | null>(null)
  const [meta, setMeta] = useState<Meta | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<Tab>('files')

  const load = useCallback(async () => {
    try {
      const [res, m] = await Promise.all([api.get<{ case: CaseDetail }>(`/cases/${id}`), api.get<Meta>('/meta')])
      setCase(res.case)
      setMeta(m)
    } catch (e) {
      setError(errMessage(e))
    }
  }, [id])

  useEffect(() => { setCase(null); setError(null); void load() }, [load])

  async function changeStatus(status: string) {
    if (!c) return
    try {
      setCase((await api.patch<{ case: CaseDetail }>(`/cases/${c.id}/status`, { status })).case)
    } catch (e) {
      setError(errMessage(e))
    }
  }

  if (error && !c) {
    return (
      <div className="space-y-4">
        <Link to="/cases" className="text-sm text-lock-400 hover:underline">← Kembali ke daftar kasus</Link>
        <ErrorBox message={error} />
      </div>
    )
  }
  if (!c || !meta) return <p className="text-slate-400">Memuat…</p>

  const tabs: { key: Tab; label: string }[] = [
    { key: 'files', label: 'Berkas Bukti' },
    { key: 'messages', label: 'Pesan' },
    { key: 'members', label: 'Anggota' },
  ]
  if (c.me.can_manage) tabs.push({ key: 'audit', label: 'Audit Log' })

  return (
    <div className="space-y-6">
      <Link to="/cases" className="text-sm text-lock-400 hover:underline">← Kasus</Link>

      <div className="card space-y-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="font-mono text-xs text-slate-400">{c.case_number} · {meta.categories[c.category] ?? c.category}</div>
            <h1 className="break-words text-2xl font-bold text-white">{c.title}</h1>
          </div>
          {c.me.can_manage ? (
            <select className="input !w-auto" value={c.status} onChange={(e) => void changeStatus(e.target.value)} aria-label="Status kasus">
              {meta.statuses.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          ) : <StatusBadge status={c.status} />}
        </div>
        {c.description && <p className="whitespace-pre-wrap text-sm text-slate-300">{c.description}</p>}
        <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-400">
          <span>Pemilik: <span className="text-slate-200">{c.owner.name}</span></span>
          {c.reporter && <span>Pelapor: <span className="text-slate-200">{c.reporter}</span></span>}
          <span>Dibuat: {fmtDate(c.created_at)}</span>
        </div>
        <ErrorBox message={error} />
      </div>

      <div className="flex gap-1 overflow-x-auto border-b border-ink-700">
        {tabs.map((t) => (
          <button key={t.key} onClick={() => setTab(t.key)}
            className={`whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition ${tab === t.key ? 'border-lock-500 text-lock-400' : 'border-transparent text-slate-400 hover:text-slate-200'}`}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'files' && <FilesTab c={c} maxMb={meta.max_file_mb} />}
      {tab === 'messages' && <MessagesTab c={c} />}
      {tab === 'members' && <MembersTab c={c} onChange={setCase} />}
      {tab === 'audit' && <CaseAudit caseId={c.id} />}
    </div>
  )
}

function CaseAudit({ caseId }: { caseId: number }) {
  const [rows, setRows] = useState<AuditRow[]>([])
  const [page, setPage] = useState(1)
  const [last, setLast] = useState(1)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api.get<{ logs: AuditRow[]; meta: { last_page: number } }>(`/audit?case_id=${caseId}&page=${page}&per_page=25`)
      .then((r) => { setRows(r.logs); setLast(r.meta.last_page) })
      .catch((e) => setError(errMessage(e)))
  }, [caseId, page])

  return (
    <div className="space-y-3">
      <ErrorBox message={error} />
      <AuditTable rows={rows} />
      <div className="flex items-center justify-end gap-2 text-sm text-slate-400">
        <button className="btn-ghost !py-1" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>‹</button>
        <span>Hal. {page} / {last}</span>
        <button className="btn-ghost !py-1" disabled={page >= last} onClick={() => setPage((p) => p + 1)}>›</button>
      </div>
    </div>
  )
}
