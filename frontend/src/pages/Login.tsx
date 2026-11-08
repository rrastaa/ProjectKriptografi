import { useState, type FormEvent } from 'react'
import { useAuth } from '../context/AuthContext'
import { api, errMessage } from '../lib/api'
import { getAssertion, passkeyErrorMessage, passkeySupported } from '../lib/webauthn'
import { ErrorBox, LockIcon } from '../components/ui'
import type { User } from '../lib/types'

type LoginResponse =
  | { token: string; user: User }
  | { passkey_required: true; ticket: string; options: unknown }

export default function Login() {
  const { setSession } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState<{ ticket: string; options: unknown } | null>(null)

  async function verifyPasskey(p: { ticket: string; options: unknown }) {
    setBusy(true)
    setError(null)
    try {
      const assertion = await getAssertion(p.options)
      const res = await api.post<{ token: string; user: User }>('/auth/login/passkey', { ticket: p.ticket, assertion })
      setSession(res.token, res.user)
    } catch (e) {
      setError(e instanceof DOMException ? passkeyErrorMessage(e) : errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  async function onSubmit(ev: FormEvent) {
    ev.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<LoginResponse>('/auth/login', { email, password })
      if ('passkey_required' in res) {
        setPending(res)
        setBusy(false)
        await verifyPasskey(res)
        return
      }
      setSession(res.token, res.user)
    } catch (e) {
      setError(errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  async function passkeyOnly() {
    if (!email) {
      setError('Isi email terlebih dahulu.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const { options } = await api.post<{ options: unknown }>('/auth/passkey/options', { email })
      const assertion = await getAssertion(options)
      const res = await api.post<{ token: string; user: User }>('/auth/passkey/login', { email, assertion })
      setSession(res.token, res.user)
    } catch (e) {
      setError(e instanceof DOMException ? passkeyErrorMessage(e) : errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-lock-500/15 text-lock-400">
            <LockIcon className="h-8 w-8" />
          </div>
          <h1 className="text-2xl font-bold tracking-wide text-white">CASELOCK</h1>
          <p className="mt-1 text-sm text-slate-400">Confidential Case Management System</p>
        </div>

        <div className="card">
          {pending ? (
            <div className="space-y-4">
              <p className="text-sm text-slate-300">
                Password benar. Verifikasi identitas Anda dengan <strong>passkey</strong>: Face ID, sidik jari, atau USB Security Key.
              </p>
              <ErrorBox message={error} />
              <button className="btn-primary w-full" disabled={busy} onClick={() => void verifyPasskey(pending)}>
                {busy ? 'Menunggu perangkat…' : 'Verifikasi dengan Passkey'}
              </button>
              <button className="btn-ghost w-full" onClick={() => { setPending(null); setPassword(''); setError(null) }}>Kembali</button>
            </div>
          ) : (
            <form onSubmit={onSubmit} className="space-y-4">
              <div>
                <label className="label" htmlFor="email">Email</label>
                <input id="email" type="email" className="input" autoComplete="username webauthn" required value={email} onChange={(e) => setEmail(e.target.value)} />
              </div>
              <div>
                <label className="label" htmlFor="password">Password</label>
                <input id="password" type="password" className="input" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
              </div>
              <ErrorBox message={error} />
              <button type="submit" className="btn-primary w-full" disabled={busy || !password}>
                {busy ? 'Memproses…' : 'Masuk'}
              </button>
              {passkeySupported() && (
                <>
                  <div className="flex items-center gap-3 text-xs text-slate-500"><span className="h-px flex-1 bg-ink-600" />atau<span className="h-px flex-1 bg-ink-600" /></div>
                  <button type="button" className="btn-ghost w-full" disabled={busy} onClick={() => void passkeyOnly()}>
                    Masuk dengan Passkey (tanpa password)
                  </button>
                </>
              )}
            </form>
          )}
        </div>
        <p className="mt-4 text-center text-xs text-slate-500">
          Akun dibuat oleh admin. Daftarkan passkey setelah login di menu “Keamanan Akun”.
        </p>
      </div>
    </div>
  )
}
