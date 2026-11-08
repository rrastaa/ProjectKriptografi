const TOKEN_KEY = 'caselock_token'

// Token disimpan di sessionStorage: hilang saat tab/browser ditutup.
export const tokenStore = {
  get: (): string | null => {
    try { return sessionStorage.getItem(TOKEN_KEY) } catch { return null }
  },
  set: (t: string) => { try { sessionStorage.setItem(TOKEN_KEY, t) } catch { /* abaikan */ } },
  clear: () => { try { sessionStorage.removeItem(TOKEN_KEY) } catch { /* abaikan */ } },
}

export class ApiError extends Error {
  status: number
  errors?: Record<string, string[]>
  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

async function raw(method: Method, path: string, body?: unknown): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = tokenStore.get()
  if (token) headers.Authorization = `Bearer ${token}`

  let payload: BodyInit | undefined
  if (body instanceof FormData) {
    payload = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  const res = await fetch(`/api${path}`, { method, headers, body: payload })

  if (!res.ok) {
    let data: { message?: string; errors?: Record<string, string[]> } = {}
    try { data = await res.json() } catch { /* bukan JSON */ }
    if (res.status === 401 && token) {
      tokenStore.clear()
      window.dispatchEvent(new Event('caselock:unauthorized'))
    }
    const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined
    throw new ApiError(firstError ?? data.message ?? `Permintaan gagal (${res.status})`, res.status, data.errors)
  }
  return res
}

async function json<T>(method: Method, path: string, body?: unknown): Promise<T> {
  const res = await raw(method, path, body)
  return (await res.json()) as T
}

export const api = {
  get: <T>(path: string) => json<T>('GET', path),
  post: <T>(path: string, body?: unknown) => json<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => json<T>('PUT', path, body),
  patch: <T>(path: string, body?: unknown) => json<T>('PATCH', path, body),
  del: <T>(path: string) => json<T>('DELETE', path),
  /** POST yang membalas data biner (dekripsi berkas). */
  postBlob: async (path: string, body: unknown) => {
    const res = await raw('POST', path, body)
    return {
      blob: await res.blob(),
      name: decodeURIComponent(res.headers.get('X-CaseLock-Name') ?? 'berkas'),
      mime: res.headers.get('X-CaseLock-Mime') ?? 'application/octet-stream',
      sha256: res.headers.get('X-CaseLock-SHA256') ?? '',
    }
  },
}

export function errMessage(e: unknown): string {
  return e instanceof Error ? e.message : 'Terjadi kesalahan.'
}
