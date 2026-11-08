// Vigenère Cipher (lapisan pertama pesan). Hanya huruf A–Z/a–z yang digeser; karakter lain tetap.
// Dijalankan di browser: kata kunci tidak pernah dikirim ke server.
// Catatan: Vigenère adalah cipher klasik dan lemah bila berdiri sendiri; perlindungan utama
// berasal dari lapisan AES-256-GCM di server (dan TLS saat transit).

export function normalizeKey(key: string): string {
  return key.toUpperCase().replace(/[^A-Z]/g, '')
}

function shift(text: string, key: string, dir: 1 | -1): string {
  const k = normalizeKey(key)
  if (k.length < 4) throw new Error('Kata kunci Vigenère minimal 4 huruf (A–Z).')

  let j = 0
  let out = ''
  for (const ch of text) {
    const c = ch.charCodeAt(0)
    const upper = ch.length === 1 && c >= 65 && c <= 90
    const lower = ch.length === 1 && c >= 97 && c <= 122
    if (!upper && !lower) {
      out += ch
      continue
    }
    const base = upper ? 65 : 97
    const s = k.charCodeAt(j++ % k.length) - 65
    out += String.fromCharCode(((c - base + dir * s + 26) % 26) + base)
  }
  return out
}

export const vigenereEncrypt = (text: string, key: string) => shift(text, key, 1)
export const vigenereDecrypt = (text: string, key: string) => shift(text, key, -1)
