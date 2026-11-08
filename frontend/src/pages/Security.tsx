import { useCallback, useEffect, useState } from 'react'
import { api, errMessage } from '../lib/api'
import { useAuth } from '../context/AuthContext'
import { createPasskey, passkeyErrorMessage, passkeySupported } from '../lib/webauthn'
import type { Passkey } from '../lib/types'
import { ErrorBox, InfoBox, LockIcon, fmtDate } from '../components/ui'

export default function Security() {
  const { user, refresh } = useAuth()
  const [list, setList] = useState<Passkey[] | null>(null)
  const [label, setLabel] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(async () => {
    try { setList((await api.get<{ passkeys: Passkey[] }>('/passkeys')).passkeys) } catch (e) { setError(errMessage(e)) }
  }, [])
  useEffect(() => { void load() }, [load])

  async function add() {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const { options } = await api.post<{ options: unknown }>('/passkeys/options')
      const credential = await createPasskey(options)
      await api.post('/passkeys', { label: label || 'Passkey', credential })
      setLabel('')
      setNotice('Passkey berhasil didaftarkan. Mulai login berikutnya, akun ini meminta verifikasi passkey setelah password.')
      await Promise.all([load(), refresh()])
    } catch (e) {
      setError(e instanceof DOMException ? passkeyErrorMessage(e) : errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  async function remove(id: number) {
    if (!window.confirm('Hapus passkey ini? Pastikan Anda masih punya cara login lain.')) return
    setError(null)
    try {
      await api.del(`/passkeys/${id}`)
      await Promise.all([load(), refresh()])
    } catch (e) { setError(errMessage(e)) }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-white">Keamanan Akun</h1>
        <p className="text-sm text-slate-400">{user?.name} · {user?.email}</p>
      </div>

      <div className="card space-y-4">
        <h2 className="text-lg font-semibold text-white">Passkey &amp; Security Key</h2>
        <InfoBox>
          Passkey memakai WebAuthn: <strong>Face ID / Touch ID / sidik jari / Windows Hello</strong> pada perangkat Anda, atau{' '}
          <strong>USB FIDO2 Security Key</strong> (dongle fisik). Setelah terdaftar, login meminta password + passkey, dan pembukaan berkas bisa diverifikasi dengan passkey.
        </InfoBox>
        {!passkeySupported() && <ErrorBox message="Browser ini tidak mendukung WebAuthn. Gunakan Chrome, Edge, Safari, atau Firefox versi terbaru lewat localhost atau HTTPS." />}
        <ErrorBox message={error} />
        {notice && <div className="rounded-lg border border-lock-500/30 px-3 py-2 text-sm text-lock-400">{notice}</div>}

        <div className="flex flex-wrap gap-2">
          <input className="input !w-64" placeholder="Nama perangkat (mis. iPhone, YubiKey)" maxLength={60} value={label} onChange={(e) => setLabel(e.target.value)} />
          <button className="btn-primary" disabled={busy || !passkeySupported()} onClick={() => void add()}>{busy ? 'Menunggu perangkat…' : '+ Daftarkan passkey'}</button>
        </div>

        <div className="divide-y divide-ink-700 rounded-lg border border-ink-700">
          {list === null && <div className="px-4 py-3 text-sm text-slate-400">Memuat…</div>}
          {list?.length === 0 && <div className="px-4 py-3 text-sm text-slate-400">Belum ada passkey. Akun ini masih login dengan password saja.</div>}
          {list?.map((p) => (
            <div key={p.id} className="flex items-center gap-3 px-4 py-3">
              <LockIcon className="h-5 w-5 text-lock-400" />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium text-white">{p.label}</div>
                <div className="text-xs text-slate-500">Dibuat {fmtDate(p.created_at)} · terakhir dipakai {fmtDate(p.last_used_at)}</div>
              </div>
              <button className="btn-danger !px-2.5 !py-1.5" onClick={() => void remove(p.id)}>Hapus</button>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
