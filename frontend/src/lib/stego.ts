// Steganografi LSB: menyisipkan data ke bit paling tidak signifikan kanal R, G, B (alpha dilewati).
// Seluruh proses berjalan di browser. Keluaran HARUS disimpan sebagai PNG (lossless);
// JPEG / kompresi lossy akan merusak data tersembunyi.
//
// Format payload tersembunyi:  "CLK1" (4 byte) | panjang uint32 big-endian (4 byte) | data
// Format data:                 [0x00 | teks UTF-8]   atau   [0x01 | salt16 | iv12 | AES-GCM(teks)]

const MAGIC = [0x43, 0x4c, 0x4b, 0x31] // "CLK1"
const HEADER = 8
const PBKDF2_ITERATIONS = 250_000

export function capacityBytes(width: number, height: number): number {
  return Math.max(0, Math.floor((width * height * 3) / 8) - HEADER)
}

/** Nomor kanal (0 = R piksel 0, 1 = G, 2 = B, 3 = R piksel 1, ...) -> indeks array RGBA. */
const idx = (channel: number) => Math.floor(channel / 3) * 4 + (channel % 3)

export function embedPayload(rgba: Uint8ClampedArray | Uint8Array, payload: Uint8Array): void {
  const total = (HEADER + payload.length) * 8
  const channels = Math.floor(rgba.length / 4) * 3
  if (total > channels) {
    throw new Error(`Gambar terlalu kecil. Kapasitas ${Math.max(0, Math.floor(channels / 8) - HEADER)} byte, dibutuhkan ${payload.length} byte.`)
  }

  const data = new Uint8Array(HEADER + payload.length)
  data.set(MAGIC, 0)
  new DataView(data.buffer).setUint32(4, payload.length, false)
  data.set(payload, HEADER)

  for (let bit = 0; bit < total; bit++) {
    const b = (data[bit >> 3] >> (7 - (bit & 7))) & 1
    const i = idx(bit)
    rgba[i] = (rgba[i] & 0xfe) | b
  }
}

export function extractPayload(rgba: Uint8ClampedArray | Uint8Array): Uint8Array {
  const channels = Math.floor(rgba.length / 4) * 3
  const readBytes = (startByte: number, count: number): Uint8Array => {
    const out = new Uint8Array(count)
    for (let n = 0; n < count * 8; n++) {
      const channel = startByte * 8 + n
      if (channel >= channels) throw new Error('Data tersembunyi terpotong.')
      out[n >> 3] |= (rgba[idx(channel)] & 1) << (7 - (n & 7))
    }
    return out
  }

  const head = readBytes(0, HEADER)
  if (!MAGIC.every((m, i) => head[i] === m)) {
    throw new Error('Tidak ditemukan data CASELOCK pada gambar ini (atau gambar sudah dikompres/diubah).')
  }
  const len = new DataView(head.buffer).getUint32(4, false)
  if (len > Math.floor(channels / 8) - HEADER) throw new Error('Header data tersembunyi tidak valid.')
  return readBytes(HEADER, len)
}

/* ------------------------------ Lapisan teks + passphrase opsional ------------------------------ */

async function deriveKey(passphrase: string, salt: Uint8Array): Promise<CryptoKey> {
  const material = await crypto.subtle.importKey('raw', new TextEncoder().encode(passphrase), 'PBKDF2', false, ['deriveKey'])
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt: salt as BufferSource, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    material,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  )
}

export async function packMessage(text: string, passphrase?: string): Promise<Uint8Array> {
  const bytes = new TextEncoder().encode(text)
  if (!passphrase) {
    const out = new Uint8Array(1 + bytes.length)
    out[0] = 0
    out.set(bytes, 1)
    return out
  }
  const salt = crypto.getRandomValues(new Uint8Array(16))
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const key = await deriveKey(passphrase, salt)
  const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv as BufferSource }, key, bytes as BufferSource))
  const out = new Uint8Array(1 + 16 + 12 + ct.length)
  out[0] = 1
  out.set(salt, 1)
  out.set(iv, 17)
  out.set(ct, 29)
  return out
}

export async function unpackMessage(data: Uint8Array, passphrase?: string): Promise<string> {
  if (data.length < 1) throw new Error('Data kosong.')
  if (data[0] === 0) return new TextDecoder().decode(data.slice(1))
  if (data[0] !== 1 || data.length < 29 + 16) throw new Error('Format data tidak dikenali.')
  if (!passphrase) throw new Error('Pesan ini dilindungi passphrase. Masukkan passphrase untuk membukanya.')
  const key = await deriveKey(passphrase, data.slice(1, 17))
  try {
    const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: data.slice(17, 29) as BufferSource }, key, data.slice(29) as BufferSource)
    return new TextDecoder().decode(pt)
  } catch {
    throw new Error('Passphrase salah atau data rusak.')
  }
}

/* ------------------------------ Canvas (hanya di browser) ------------------------------ */

export async function loadImageData(file: File, flattenOnWhite: boolean): Promise<ImageData> {
  const bmp = await createImageBitmap(file)
  const canvas = document.createElement('canvas')
  canvas.width = bmp.width
  canvas.height = bmp.height
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) throw new Error('Canvas tidak tersedia.')
  if (flattenOnWhite) {
    // Piksel transparan akan kehilangan nilai RGB-nya; ratakan di atas putih supaya data aman.
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
  }
  ctx.drawImage(bmp, 0, 0)
  bmp.close()
  return ctx.getImageData(0, 0, canvas.width, canvas.height)
}

export function imageDataToPngBlob(img: ImageData): Promise<Blob> {
  const canvas = document.createElement('canvas')
  canvas.width = img.width
  canvas.height = img.height
  canvas.getContext('2d')!.putImageData(img, 0, 0)
  return new Promise((resolve, reject) =>
    canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Gagal membuat PNG.'))), 'image/png'),
  )
}
