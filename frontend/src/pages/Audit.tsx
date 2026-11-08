import { useEffect, useState } from 'react'
import { api, errMessage } from '../lib/api'
import type { AuditRow } from '../lib/types'
import { ErrorBox } from '../components/ui'
import AuditTable from '../components/AuditTable'

interface Verify { valid: boolean; checked: number; broken_at_seq: number | null; reason: string | null; head_hash: string }

export default function AuditPage() {
  const [rows, setRows] = useState<AuditRow[]>([])
  const [page, setPage] = useState(1)
  const [last, setLast] = useState(1)
  const [total, setTotal] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [verify, setVerify] = useState<Verify | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get<{ logs: AuditRow[]; meta: { last_page: number; total: number } }>(`/audit?page=${page}&per_page=25`)
      .then((r) => { setRows(r.logs); setLast(r.meta.last_page); setTotal(r.meta.total) })
      .catch((e) => setError(errMessage(e)))
  }, [page])

  async function runVerify() {
    setBusy(true)
    setError(null)
    try { setVerify(await api.post<Verify>('/audit/verify')) } catch (e) { setError(errMessage(e)) } finally { setBusy(false) }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-white">Audit Log</h1>
          <p className="text-sm text-slate-400">{total.toLocaleString('id-ID')} catatan · setiap baris di-hash berantai (SHA-256) dengan baris sebelumnya.</p>
        </div>
        <button className="btn-primary" disabled={busy} onClick={() => void runVerify()}>{busy ? 'Memeriksa…' : 'Verifikasi integritas rantai'}</button>
      </div>

      <ErrorBox message={error} />
      {verify && (
        <div className={`rounded-lg border px-4 py-3 text-sm ${verify.valid ? 'border-lock-500/40 bg-lock-500/10 text-lock-400' : 'border-red-500/40 bg-red-500/10 text-red-200'}`}>
          {verify.valid
            ? <>✓ Rantai utuh: {verify.checked} catatan terverifikasi. Hash ujung: <span className="break-all font-mono text-xs">{verify.head_hash}</span></>
            : <>⚠ Rantai TERPUTUS pada catatan #{verify.broken_at_seq}: {verify.reason}</>}
        </div>
      )}

      <AuditTable rows={rows} />
      <div className="flex items-center justify-end gap-2 text-sm text-slate-400">
        <button className="btn-ghost !py-1" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>‹</button>
        <span>Hal. {page} / {last}</span>
        <button className="btn-ghost !py-1" disabled={page >= last} onClick={() => setPage((p) => p + 1)}>›</button>
      </div>
    </div>
  )
}
