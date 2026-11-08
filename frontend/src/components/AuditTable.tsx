import type { AuditRow } from '../lib/types'
import { fmtDate } from './ui'

const ACTION_LABEL: Record<string, string> = {
  'auth.login': 'Login',
  'auth.login_failed': 'Login gagal',
  'auth.passkey_failed': 'Passkey gagal',
  'auth.logout': 'Logout',
  'passkey.register': 'Passkey didaftarkan',
  'passkey.remove': 'Passkey dihapus',
  'user.create': 'Pengguna dibuat',
  'user.update': 'Pengguna diubah',
  'case.create': 'Kasus dibuat',
  'case.view': 'Kasus dilihat',
  'case.status': 'Status kasus diubah',
  'case.member.add': 'Anggota ditambah',
  'case.member.remove': 'Anggota dihapus',
  'file.upload': 'Berkas diunggah',
  'file.decrypt': 'Berkas DIBUKA',
  'file.decrypt_denied': 'Pembukaan berkas DITOLAK',
  'file.integrity_failed': 'Integritas berkas GAGAL',
  'file.permission.update': 'Izin berkas diubah',
  'message.send': 'Pesan dikirim',
  'message.open': 'Pesan dibuka',
  'message.integrity_failed': 'Integritas pesan GAGAL',
  'audit.verify': 'Verifikasi rantai audit',
}

function tone(action: string): string {
  if (action.includes('denied') || action.includes('failed')) return 'text-red-300'
  if (action === 'file.decrypt' || action === 'message.open') return 'text-amber-300'
  return 'text-slate-200'
}

export default function AuditTable({ rows }: { rows: AuditRow[] }) {
  return (
    <div className="card overflow-x-auto !p-0">
      <table className="w-full min-w-[760px]">
        <thead className="border-b border-ink-700">
          <tr><th className="th">#</th><th className="th">Waktu</th><th className="th">Pengguna</th><th className="th">Aksi</th><th className="th">Objek</th><th className="th">Hash</th></tr>
        </thead>
        <tbody className="divide-y divide-ink-700">
          {rows.length === 0 && <tr><td className="td text-slate-400" colSpan={6}>Belum ada catatan.</td></tr>}
          {rows.map((r) => (
            <tr key={r.seq} className="align-top">
              <td className="td font-mono text-xs text-slate-400">{r.seq}</td>
              <td className="td whitespace-nowrap text-xs text-slate-400">{fmtDate(r.logged_at)}</td>
              <td className="td">{r.user ?? <span className="text-slate-500">—</span>}</td>
              <td className={`td font-medium ${tone(r.action)}`}>
                {ACTION_LABEL[r.action] ?? r.action}
                {r.details && Object.keys(r.details).filter((k) => k !== 'ip').length > 0 && (
                  <div className="mt-0.5 break-all font-mono text-[11px] font-normal text-slate-500">
                    {Object.entries(r.details).filter(([k]) => k !== 'ip').map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(' · ')}
                  </div>
                )}
              </td>
              <td className="td font-mono text-xs text-slate-400">{r.subject_type ? `${r.subject_type}:${(r.subject_id ?? '').slice(0, 8)}` : '—'}</td>
              <td className="td font-mono text-[11px] text-slate-500" title={`prev: ${r.prev_hash}\nhash: ${r.hash}`}>{r.hash.slice(0, 12)}…</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
