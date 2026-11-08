import { useState, type FormEvent } from 'react'
import { api, errMessage } from '../lib/api'
import { useAuth } from '../context/AuthContext'
import { getAssertion, passkeyErrorMessage, passkeySupported } from '../lib/webauthn'
import type { StepUpAuth } from '../lib/types'
import { ErrorBox, LockIcon, Modal } from './ui'

/**
 * Re-autentikasi sebelum membuka (mendekripsi) satu berkas.
 * Pilihan: passkey (Face ID / sidik jari / USB Security Key) atau password.
 */
export default function ReauthModal({
  fileId,
  fileName,
  onAuthorize,
  onClose,
}: {
  fileId: string
  fileName: string
  onAuthorize: (auth: StepUpAuth) => Promise<void>
  onClose: () => void
}) {
  const { user } = useAuth()
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const canPasskey = (user?.passkeys_count ?? 0) > 0 && passkeySupported()

  async function run(fn: () => Promise<StepUpAuth>) {
    setBusy(true)
    setError(null)
    try {
      await onAuthorize(await fn())
    } catch (e) {
      setError(e instanceof DOMException ? passkeyErrorMessage(e) : errMessage(e))
      setBusy(false)
    }
  }

  const withPasskey = () =>
    run(async () => {
      const { options } = await api.post<{ options: unknown }>(`/files/${fileId}/step-up`)
      return { method: 'passkey', assertion: await getAssertion(options) }
    })

  const withPassword = (e: FormEvent) => {
    e.preventDefault()
    void run(async () => ({ method: 'password', password }))
  }

  return (
    <Modal title="Verifikasi untuk membuka berkas" onClose={onClose}>
      <div className="mb-4 flex items-start gap-3 rounded-lg bg-ink-900 p-3">
        <LockIcon className="mt-0.5 h-5 w-5 shrink-0 text-lock-400" />
        <div className="min-w-0">
          <div className="truncate text-sm font-medium text-white">{fileName}</div>
          <p className="text-xs text-slate-400">Hanya berkas ini yang akan didekripsi. Berkas lain di kasus ini tetap terkunci. Tindakan ini dicatat di audit log.</p>
        </div>
      </div>

      <div className="space-y-4">
        {canPasskey && (
          <button className="btn-primary w-full" disabled={busy} onClick={() => void withPasskey()}>
            {busy ? 'Menunggu perangkat…' : 'Verifikasi dengan Passkey'}
          </button>
        )}
        {canPasskey && <div className="text-center text-xs text-slate-500">atau gunakan password</div>}
        <form onSubmit={withPassword} className="space-y-3">
          <div>
            <label className="label" htmlFor="reauth-pw">Password</label>
            <input id="reauth-pw" type="password" className="input" autoComplete="current-password" autoFocus={!canPasskey} value={password} onChange={(e) => setPassword(e.target.value)} />
          </div>
          <ErrorBox message={error} />
          <button className={canPasskey ? 'btn-ghost w-full' : 'btn-primary w-full'} disabled={busy || !password}>Buka dengan Password</button>
        </form>
      </div>
    </Modal>
  )
}
