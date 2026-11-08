import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, tokenStore } from '../lib/api'
import type { User } from '../lib/types'

interface AuthState {
  user: User | null
  loading: boolean
  setSession: (token: string, user: User) => void
  refresh: () => Promise<void>
  logout: () => Promise<void>
}

const Ctx = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState<boolean>(!!tokenStore.get())

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) {
      setUser(null)
      setLoading(false)
      return
    }
    try {
      const res = await api.get<{ user: User }>('/auth/me')
      setUser(res.user)
    } catch {
      tokenStore.clear()
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
    const onUnauthorized = () => setUser(null)
    window.addEventListener('caselock:unauthorized', onUnauthorized)
    return () => window.removeEventListener('caselock:unauthorized', onUnauthorized)
  }, [refresh])

  const value = useMemo<AuthState>(
    () => ({
      user,
      loading,
      setSession: (token, u) => {
        tokenStore.set(token)
        setUser(u)
        setLoading(false)
      },
      refresh,
      logout: async () => {
        try { await api.post('/auth/logout') } catch { /* sesi mungkin sudah berakhir */ }
        tokenStore.clear()
        setUser(null)
      },
    }),
    [user, loading, refresh],
  )

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useAuth(): AuthState {
  const v = useContext(Ctx)
  if (!v) throw new Error('useAuth harus dipakai di dalam AuthProvider')
  return v
}
