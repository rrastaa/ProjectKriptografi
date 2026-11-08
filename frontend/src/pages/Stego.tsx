import { useState } from 'react'
import { ErrorBox, InfoBox } from '../components/ui'
import {
  capacityBytes,
  embedPayload,
  extractPayload,
  imageDataToPngBlob,
  loadImageData,
  packMessage,
  unpackMessage,
} from '../lib/stego'
import { errMessage } from '../lib/api'

export default function Stego() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-white">Steganografi LSB</h1>
        <p className="text-sm text-slate-400">Sisipkan catatan tersembunyi ke dalam gambar. Semua proses berjalan di browser Anda; tidak ada yang dikirim ke server.</p>
      </div>
      <InfoBox>
        Simpan hasilnya sebagai <strong>PNG</strong> dan jangan dikompres ulang (screenshot, WhatsApp, atau konversi ke JPEG akan menghancurkan data tersembunyi).
        Untuk perlindungan nyata, isi passphrase: pesan akan dienkripsi AES-256-GCM sebelum disisipkan. Gambar hasil dapat diunggah sebagai berkas bukti di kasus.
      </InfoBox>
      <div className="grid gap-6 lg:grid-cols-2">
        <Embed />
        <Extract />
      </div>
    </div>
  )
}

function Embed() {
  const [file, setFile] = useState<File | null>(null)
  const [capacity, setCapacity] = useState<number | null>(null)
  const [secret, setSecret] = useState('')
  const [pass, setPass] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ url: string; name: string } | null>(null)

  async function pick(f: File | null) {
    setFile(f)
    setResult(null)
    setError(null)
    setCapacity(null)
    if (!f) return
    try {
      const bmp = await createImageBitmap(f)
      setCapacity(capacityBytes(bmp.width, bmp.height))
      bmp.close()
    } catch {
      setError('File bukan gambar yang dapat dibaca browser.')
    }
  }

  async function run() {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const img = await loadImageData(file, true)
      embedPayload(img.data, await packMessage(secret, pass || undefined))
      const blob = await imageDataToPngBlob(img)
      if (result) URL.revokeObjectURL(result.url)
      setResult({ url: URL.createObjectURL(blob), name: file.name.replace(/\.[^.]+$/, '') + '-stego.png' })
    } catch (e) {
      setError(errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card space-y-4">
      <h2 className="text-lg font-semibold text-white">Sisipkan pesan</h2>
      <div>
        <label className="label" htmlFor="cover">Gambar sampul (PNG / JPG / WebP)</label>
        <input id="cover" type="file" accept="image/*" className="text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-600 file:px-3 file:py-2 file:text-sm file:text-white" onChange={(e) => void pick(e.target.files?.[0] ?? null)} />
        {capacity !== null && <p className="mt-1 text-xs text-slate-500">Kapasitas maksimal ≈ {capacity.toLocaleString('id-ID')} byte.</p>}
      </div>
      <div>
        <label className="label" htmlFor="secret">Pesan rahasia</label>
        <textarea id="secret" className="input min-h-[110px]" value={secret} onChange={(e) => setSecret(e.target.value)} />
      </div>
      <div>
        <label className="label" htmlFor="pp">Passphrase (disarankan)</label>
        <input id="pp" type="password" autoComplete="off" className="input" value={pass} onChange={(e) => setPass(e.target.value)} />
      </div>
      <ErrorBox message={error} />
      <button className="btn-primary" disabled={busy || !file || !secret} onClick={() => void run()}>{busy ? 'Memproses…' : 'Sisipkan ke gambar'}</button>
      {result && (
        <div className="space-y-2">
          <img src={result.url} alt="Hasil steganografi" className="max-h-60 rounded-lg" />
          <a className="btn-ghost" href={result.url} download={result.name}>Unduh {result.name}</a>
        </div>
      )}
    </div>
  )
}

function Extract() {
  const [file, setFile] = useState<File | null>(null)
  const [pass, setPass] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [text, setText] = useState<string | null>(null)

  async function run() {
    if (!file) return
    setBusy(true)
    setError(null)
    setText(null)
    try {
      const img = await loadImageData(file, false)
      setText(await unpackMessage(extractPayload(img.data), pass || undefined))
    } catch (e) {
      setError(errMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card space-y-4">
      <h2 className="text-lg font-semibold text-white">Ekstrak pesan</h2>
      <div>
        <label className="label" htmlFor="stego">Gambar PNG hasil steganografi</label>
        <input id="stego" type="file" accept="image/png" className="text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-600 file:px-3 file:py-2 file:text-sm file:text-white" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setText(null); setError(null) }} />
      </div>
      <div>
        <label className="label" htmlFor="pp2">Passphrase (jika dipakai)</label>
        <input id="pp2" type="password" autoComplete="off" className="input" value={pass} onChange={(e) => setPass(e.target.value)} />
      </div>
      <ErrorBox message={error} />
      <button className="btn-primary" disabled={busy || !file} onClick={() => void run()}>{busy ? 'Memproses…' : 'Ekstrak'}</button>
      {text !== null && <div className="whitespace-pre-wrap rounded-lg bg-lock-500/10 p-3 text-sm text-white">{text}</div>}
    </div>
  )
}
