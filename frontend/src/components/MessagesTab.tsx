import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, errMessage } from '../lib/api'
import type { CaseDetail, MessageItem } from '../lib/types'
import { vigenereDecrypt, vigenereEncrypt } from '../lib/vigenere'
import { ErrorBox, InfoBox, LockIcon, fmtDate } from './ui'

export default function MessagesTab({ c }: { c: CaseDetail }) {
  const [messages, setMessages] = useState<MessageItem[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [keyword, setKeyword] = useState('')
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setMessages((await api.get<{ messages: MessageItem[] }>(`/cases/${c.id}/messages`)).messages)
    } catch (e) {
      setError(errMessage(e))
    }
  }, [c.id])

  useEffect(() => { void load() }, [load])

  async function send(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const cipher = vigenereEncrypt(text, keyword) // lapisan 1: di browser, kunci tidak dikirim
      await api.post(`/cases/${c.id}/messages`, { vigenere_ciphertext: cipher }) // lapisan 2: AES-256-GCM di server
      setText('')
      await load()
    } catch (err) {
      setError(errMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      <InfoBox>
        Pesan dilindungi dua lapis: <strong>Vigenère Cipher</strong> dengan kata kunci Anda (diproses di browser, tidak dikirim ke server),
        lalu <strong>AES-256-GCM</strong> di server. Bagikan kata kunci kepada penerima lewat jalur terpisah.
      </InfoBox>
      <ErrorBox message={error} />

      <div className="space-y-3">
        {messages === null && <p className="text-sm text-slate-400">Memuat…</p>}
        {messages?.length === 0 && <p className="text-sm text-slate-400">Belum ada pesan.</p>}
        {messages?.map((m) => <MessageRow key={m.id} caseId={c.id} m={m} />)}
      </div>

      {c.me.can_contribute && (
        <form onSubmit={send} className="card space-y-3">
          <div className="grid gap-3 sm:grid-cols-[1fr_220px]">
            <div>
              <label className="label" htmlFor="msg">Pesan baru</label>
              <textarea id="msg" className="input min-h-[90px]" required maxLength={5000} value={text} onChange={(e) => setText(e.target.value)} />
            </div>
            <div>
              <label className="label" htmlFor="vk">Kata kunci Vigenère</label>
              <input id="vk" className="input" type="password" autoComplete="off" placeholder="min. 4 huruf A–Z" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
            </div>
          </div>
          <div className="flex justify-end">
            <button className="btn-primary" disabled={busy || !text.trim() || keyword.replace(/[^A-Za-z]/g, '').length < 4}>{busy ? 'Mengirim…' : 'Enkripsi & Kirim'}</button>
          </div>
        </form>
      )}
    </div>
  )
}

function MessageRow({ caseId, m }: { caseId: number; m: MessageItem }) {
  const [cipher, setCipher] = useState<string | null>(null)
  const [keyword, setKeyword] = useState('')
  const [plain, setPlain] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function open() {
    setBusy(true)
    setError(null)
    try {
      const res = await api.get<{ message: { vigenere_ciphertext: string } }>(`/cases/${caseId}/messages/${m.id}`)
      setCipher(res.message.vigenere_ciphertext)
    } catch (e) {
      setError(errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  function decode(e: FormEvent) {
    e.preventDefault()
    try { setPlain(vigenereDecrypt(cipher ?? '', keyword)); setError(null) } catch (err) { setError(errMessage(err)) }
  }

  return (
    <div className="card !p-4">
      <div className="mb-2 flex items-center justify-between text-xs text-slate-400">
        <span className="font-medium text-slate-200">{m.sender}</span>
        <span>{fmtDate(m.created_at)}</span>
      </div>

      {cipher === null ? (
        <div className="flex items-center gap-3">
          <LockIcon className="h-4 w-4 text-slate-500" />
          <span className="flex-1 text-sm text-slate-500">Pesan terenkripsi</span>
          <button className="btn-ghost !py-1.5" disabled={busy} onClick={() => void open()}>{busy ? '…' : 'Buka'}</button>
        </div>
      ) : (
        <div className="space-y-2">
          <div className="break-all rounded-lg bg-ink-900 p-3 font-mono text-xs text-slate-400">{cipher}</div>
          {plain === null ? (
            <form onSubmit={decode} className="flex gap-2">
              <input className="input" type="password" autoComplete="off" placeholder="Kata kunci Vigenère" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
              <button className="btn-primary" disabled={!keyword}>Dekripsi</button>
            </form>
          ) : (
            <div className="whitespace-pre-wrap rounded-lg bg-lock-500/10 p-3 text-sm text-white">{plain}</div>
          )}
        </div>
      )}
      <div className="mt-2"><ErrorBox message={error} /></div>
    </div>
  )
}
