// Helper WebAuthn (Passkeys: Face ID / sidik jari / USB FIDO2 Security Key)

export function b64uToBuf(s: string): ArrayBuffer {
  const pad = '='.repeat((4 - (s.length % 4)) % 4)
  const bin = atob(s.replace(/-/g, '+').replace(/_/g, '/') + pad)
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out.buffer
}

export function bufToB64u(buf: ArrayBuffer | ArrayBufferView): string {
  const bytes = buf instanceof ArrayBuffer ? new Uint8Array(buf) : new Uint8Array(buf.buffer, buf.byteOffset, buf.byteLength)
  let bin = ''
  for (const b of bytes) bin += String.fromCharCode(b)
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export function passkeySupported(): boolean {
  return typeof window !== 'undefined' && !!window.PublicKeyCredential && !!navigator.credentials
}

/* eslint-disable @typescript-eslint/no-explicit-any */

/** Membuat passkey baru. `options` = JSON dari server (challenge/id dalam base64url). */
export async function createPasskey(options: any): Promise<unknown> {
  const publicKey: PublicKeyCredentialCreationOptions = {
    ...options,
    challenge: b64uToBuf(options.challenge),
    user: { ...options.user, id: b64uToBuf(options.user.id) },
    excludeCredentials: (options.excludeCredentials ?? []).map((c: any) => ({ ...c, id: b64uToBuf(c.id) })),
  }
  const cred = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential | null
  if (!cred) throw new Error('Pembuatan passkey dibatalkan.')
  const r = cred.response as AuthenticatorAttestationResponse
  return {
    id: cred.id,
    rawId: bufToB64u(cred.rawId),
    type: cred.type,
    response: {
      clientDataJSON: bufToB64u(r.clientDataJSON),
      attestationObject: bufToB64u(r.attestationObject),
    },
  }
}

/** Meminta tanda tangan passkey (assertion). */
export async function getAssertion(options: any): Promise<unknown> {
  const publicKey: PublicKeyCredentialRequestOptions = {
    ...options,
    challenge: b64uToBuf(options.challenge),
    allowCredentials: (options.allowCredentials ?? []).map((c: any) => ({ ...c, id: b64uToBuf(c.id) })),
  }
  const cred = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential | null
  if (!cred) throw new Error('Verifikasi passkey dibatalkan.')
  const r = cred.response as AuthenticatorAssertionResponse
  return {
    id: cred.id,
    rawId: bufToB64u(cred.rawId),
    type: cred.type,
    response: {
      clientDataJSON: bufToB64u(r.clientDataJSON),
      authenticatorData: bufToB64u(r.authenticatorData),
      signature: bufToB64u(r.signature),
      userHandle: r.userHandle ? bufToB64u(r.userHandle) : null,
    },
  }
}

export function passkeyErrorMessage(e: unknown): string {
  if (e instanceof DOMException) {
    if (e.name === 'NotAllowedError') return 'Verifikasi dibatalkan atau waktu habis.'
    if (e.name === 'InvalidStateError') return 'Passkey ini sudah terdaftar di perangkat tersebut.'
    if (e.name === 'SecurityError') return 'Domain tidak cocok dengan CASELOCK_RP_ID / CASELOCK_ORIGINS di backend.'
  }
  return e instanceof Error ? e.message : 'Verifikasi passkey gagal.'
}
