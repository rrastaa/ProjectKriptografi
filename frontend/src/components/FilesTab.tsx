import { useCallback, useEffect, useRef, useState } from 'react'
import { api, errMessage } from '../lib/api'
import type { CaseDetail, FileItem, StepUpAuth } from '../lib/types'
import { ErrorBox, InfoBox, LockIcon, Modal, fmtDate, fmtSize } from './ui'
import ReauthModal from './ReauthModal'

interface Opened {
  name: string
  url: string
  kind: 'image' | 'text' | 'other'
  text?: string
  integrity: boolean
}

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']

async function sha256Hex(blob: Blob): Promise<string> {
  const buf = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer())
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('')
}

export default function FilesTab({ c, maxMb }: { c: CaseDetail; maxMb: number }) {
  const [files, setFiles] = useState<FileItem[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [target, setTarget] = useState<FileItem | null>(null)
  const [permFor, setPermFor] = useState<FileItem | null>(null)
  const [opened, setOpened] = useState<Opened | null>(null)
  const input = useRef<HTMLInputElement>(null)

  const load = useCallback(async () => {
    try {
      setFiles((await api.get<{ files: FileItem[] }>(`/cases/${c.id}/files`)).files)
    } catch (e) {
      setError(errMessage(e))
    }
  }, [c.id])

  useEffect(() => { void load() }, [load])
  useEffect(() => () => { if (opened) URL.revokeObjectURL(opened.url) }, [opened])

  async function upload(file: File) {
    setUploading(true)
    setError(null)
    setNotice(null)
    try {
      const form = new FormData()
      form.append('file', file)
      await api.post(`/cases/${c.id}/files`, form)
      setNotice(`“${file.name}” dienkripsi (XChaCha20-Poly1305) dan disimpan.`)
      await load()
    } catch (e) {
      setError(errMessage(e))
    } finally {
      setUploading(false)
      if (input.current) input.current.value = ''
    }
  }

  async function decrypt(auth: StepUpAuth) {
    if (!target) return
    const res = await api.postBlob(`/files/${target.id}/decrypt`, { auth })
    const integrity = res.sha256 !== '' && (await sha256Hex(res.blob)) === res.sha256
    const isImage = IMAGE_TYPES.includes(res.mime)
    const isText = res.mime.startsWith('text/') || res.mime === 'application/json'
    const typed = isImage ? new Blob([res.blob], { type: res.mime }) : res.blob
    setOpened({
      name: res.name,
      url: URL.createObjectURL(typed),
      kind: isImage ? 'image' : isText && res.blob.size <= 200_000 ? 'text' : 'other',
      text: isText && res.blob.size <= 200_000 ? await res.blob.text() : undefined,
      integrity,
    })
    setTarget(null)
  }

  return (
    <div className="space-y-4">
      <InfoBox>
        Setiap berkas dienkripsi dengan kunci acaknya sendiri. Membuka satu berkas <strong>tidak</strong> membuka berkas lain, dan setiap pembukaan dicatat di audit log.
      </InfoBox>
      <ErrorBox message={error} />
      {notice && <div className="rounded-lg border border-lock-500/30 px-3 py-2 text-sm text-lock-400">{notice}</div>}

      {c.me.can_contribute && (
        <div className="card flex flex-wrap items-center gap-3">
          <input ref={input} type="file" className="text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-600 file:px-3 file:py-2 file:text-sm file:text-white" disabled={uploading}
            onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f) }} />
          <span className="text-xs text-slate-500">{uploading ? 'Mengenkripsi dan mengunggah…' : `Maks. ${maxMb} MB per berkas`}</span>
        </div>
      )}

      <div className="card overflow-x-auto !p-0">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-ink-700">
            <tr><th className="th">Berkas</th><th className="th">Ukuran</th><th className="th">Diunggah</th><th className="th text-right">Aksi</th></tr>
          </thead>
          <tbody className="divide-y divide-ink-700">
            {files === null && <tr><td className="td text-slate-400" colSpan={4}>Memuat…</td></tr>}
            {files?.length === 0 && <tr><td className="td text-slate-400" colSpan={4}>Belum ada berkas bukti.</td></tr>}
            {files?.map((f) => (
              <tr key={f.id}>
                <td className="td">
                  <div className="flex items-center gap-2">
                    <LockIcon className={`h-4 w-4 shrink-0 ${f.can_decrypt ? 'text-lock-400' : 'text-slate-500'}`} />
                    <div className="min-w-0">
                      <div className="truncate font-medium text-white">{f.name}</div>
                      <div className="font-mono text-[11px] text-slate-500">sha256 {f.sha256.slice(0, 16)}…</div>
                    </div>
                  </div>
                </td>
                <td className="td text-slate-300">{fmtSize(f.size_bytes)}</td>
                <td className="td text-xs text-slate-400">{f.uploaded_by}<br />{fmtDate(f.created_at)}</td>
                <td className="td">
                  <div className="flex justify-end gap-2">
                    {c.me.can_manage && <button className="btn-ghost !px-2.5 !py-1.5" onClick={() => setPermFor(f)}>Izin</button>}
                    {f.can_decrypt
                      ? <button className="btn-primary !px-2.5 !py-1.5" onClick={() => { setError(null); setTarget(f) }}>Dekripsi</button>
                      : <span className="rounded-lg border border-ink-600 px-2.5 py-1.5 text-xs text-slate-500" title="Minta pemilik kasus memberi izin untuk berkas ini">Terkunci</span>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {target && <ReauthModal fileId={target.id} fileName={target.name} onAuthorize={decrypt} onClose={() => setTarget(null)} />}
      {permFor && <PermissionsModal file={permFor} onClose={() => { setPermFor(null); void load() }} />}
      {opened && <ViewerModal opened={opened} onClose={() => setOpened(null)} />}
    </div>
  )
}

function ViewerModal({ opened, onClose }: { opened: Opened; onClose: () => void }) {
  return (
    <Modal title={opened.name} onClose={onClose} wide>
      <div className="space-y-3">
        <div className={`rounded-lg px-3 py-2 text-xs ${opened.integrity ? 'bg-lock-500/10 text-lock-400' : 'bg-red-500/10 text-red-300'}`}>
          {opened.integrity ? '✓ Integritas terverifikasi (SHA-256 cocok dengan hash saat unggah).' : '⚠ Hash tidak dapat diverifikasi di browser.'}
        </div>
        {opened.kind === 'image' && <img src={opened.url} alt={opened.name} className="max-h-[60vh] w-full rounded-lg bg-ink-900 object-contain" />}
        {opened.kind === 'text' && <pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-lg bg-ink-900 p-3 font-mono text-xs text-slate-200">{opened.text}</pre>}
        {opened.kind === 'other' && <p className="text-sm text-slate-400">Pratinjau tidak tersedia untuk jenis berkas ini. Unduh untuk membukanya.</p>}
        <p className="text-xs text-slate-500">Data hanya ada di memori browser ini. Tutup jendela ini untuk membuangnya.</p>
        <div className="flex justify-end gap-2">
          <a className="btn-primary" href={opened.url} download={opened.name}>Unduh</a>
          <button className="btn-ghost" onClick={onClose}>Tutup</button>
        </div>
      </div>
    </Modal>
  )
}

function PermissionsModal({ file, onClose }: { file: FileItem; onClose: () => void }) {
  const [members, setMembers] = useState<{ id: number; name: string; role: string; granted: boolean }[] | null>(null)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get<{ members: { id: number; name: string; role: string; granted: boolean }[] }>(`/files/${file.id}/permissions`)
      .then((r) => { setMembers(r.members); setSelected(new Set(r.members.filter((m) => m.granted).map((m) => m.id))) })
      .catch((e) => setError(errMessage(e)))
  }, [file.id])

  async function save() {
    setBusy(true)
    setError(null)
    try {
      await api.put(`/files/${file.id}/permissions`, { user_ids: [...selected] })
      onClose()
    } catch (e) {
      setError(errMessage(e))
      setBusy(false)
    }
  }

  return (
    <Modal title="Izin dekripsi berkas" onClose={onClose}>
      <p className="mb-3 truncate text-sm text-slate-300">{file.name}</p>
      <div className="space-y-2">
        {members === null && <p className="text-sm text-slate-400">Memuat…</p>}
        {members?.map((m) => (
          <label key={m.id} className="flex cursor-pointer items-center gap-3 rounded-lg bg-ink-900 px-3 py-2">
            <input type="checkbox" className="h-4 w-4 accent-emerald-500" checked={selected.has(m.id)}
              onChange={(e) => setSelected((s) => { const n = new Set(s); e.target.checked ? n.add(m.id) : n.delete(m.id); return n })} />
            <span className="flex-1 text-sm text-white">{m.name}</span>
            <span className="text-xs text-slate-500">{m.role}</span>
          </label>
        ))}
      </div>
      <div className="mt-4 space-y-3">
        <ErrorBox message={error} />
        <div className="flex justify-end gap-2">
          <button className="btn-ghost" onClick={onClose}>Batal</button>
          <button className="btn-primary" disabled={busy || members === null} onClick={() => void save()}>{busy ? 'Menyimpan…' : 'Simpan izin'}</button>
        </div>
      </div>
    </Modal>
  )
}
