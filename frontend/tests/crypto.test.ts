// Uji logika Vigenère & steganografi LSB (tanpa browser).
//   npm run test:crypto      (Node >= 22.6)
import { vigenereDecrypt, vigenereEncrypt } from '../src/lib/vigenere.ts'
import { capacityBytes, embedPayload, extractPayload, packMessage, unpackMessage } from '../src/lib/stego.ts'

let pass = 0
let fail = 0
const check = (name: string, ok: boolean) => {
  ok ? pass++ : fail++
  console.log(`${ok ? '  [OK]    ' : '  [GAGAL] '}${name}`)
}
const throwsAsync = async (fn: () => Promise<unknown> | unknown) => {
  try { await fn() } catch { return true }
  return false
}

console.log('Vigenère')
const plain = 'Rapat internal Jumat 14:00 — Transfer Rp 1.250.000 ke rekening 123-456. Ünïcode OK!'
const enc = vigenereEncrypt(plain, 'Kunci Rahasia')
check('Roundtrip', vigenereDecrypt(enc, 'kunci rahasia') === plain)
check('Ciphertext berbeda dari plaintext', enc !== plain)
check('Huruf besar/kecil & non-huruf dipertahankan', enc.includes('14:00') && enc[0] === enc[0].toUpperCase())
check('Vektor uji klasik (ATTACKATDAWN / LEMON)', vigenereEncrypt('ATTACKATDAWN', 'LEMONLEMONLE'.slice(0, 5)) !== '' && vigenereEncrypt('ATTACKATDAWN', 'LEMON') === 'LXFOPVEFRNHR')
check('Kunci < 4 huruf ditolak', await throwsAsync(() => vigenereEncrypt('abc', 'ab1')))

console.log('Steganografi LSB')
const w = 64, h = 64
const px = new Uint8ClampedArray(w * h * 4)
for (let i = 0; i < px.length; i++) px[i] = (i % 4 === 3) ? 255 : Math.floor(Math.random() * 256)
const original = new Uint8ClampedArray(px)

const secret = 'Bukti: akun rekening bank 9988-7766-5544 | Passphrase tidak disimpan 🔒'
const packed = await packMessage(secret)
embedPayload(px, packed)
check('Ekstraksi tanpa passphrase', (await unpackMessage(extractPayload(px))) === secret)

let maxDiff = 0, alphaChanged = false
for (let i = 0; i < px.length; i++) {
  if (i % 4 === 3) { if (px[i] !== original[i]) alphaChanged = true } else maxDiff = Math.max(maxDiff, Math.abs(px[i] - original[i]))
}
check('Perubahan piksel maksimal 1 level (tak terlihat)', maxDiff <= 1)
check('Kanal alpha tidak disentuh', !alphaChanged)

const px2 = new Uint8ClampedArray(original)
const packedEnc = await packMessage(secret, 'passphrase kuat')
embedPayload(px2, packedEnc)
check('Ekstraksi dengan passphrase benar', (await unpackMessage(extractPayload(px2), 'passphrase kuat')) === secret)
check('Passphrase salah ditolak', await throwsAsync(() => unpackMessage(extractPayload(px2), 'salah')))
check('Tanpa passphrase ditolak untuk data terenkripsi', await throwsAsync(() => unpackMessage(extractPayload(px2))))
check('Gambar polos tidak dianggap berisi data', await throwsAsync(() => extractPayload(new Uint8ClampedArray(original))))

const tiny = new Uint8ClampedArray(4 * 4 * 4)
check('Kapasitas dihitung benar', capacityBytes(64, 64) === Math.floor((64 * 64 * 3) / 8) - 8)
check('Gambar terlalu kecil ditolak', await throwsAsync(() => embedPayload(tiny, new Uint8Array(100))))

console.log(`\nHasil: ${pass} lulus, ${fail} gagal`)
process.exit(fail === 0 ? 0 : 1)
