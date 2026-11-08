import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { LockIcon, RoleBadge } from './ui'

export default function Layout({ children }: { children: ReactNode }) {
  const { user, logout } = useAuth()
  if (!user) return null

  const links: { to: string; label: string }[] = [
    { to: '/cases', label: 'Kasus' },
    { to: '/stego', label: 'Steganografi' },
    { to: '/security', label: 'Keamanan Akun' },
  ]
  if (user.role === 'admin') {
    links.push({ to: '/audit', label: 'Audit Log' }, { to: '/users', label: 'Pengguna' })
  }

  return (
    <div className="min-h-screen md:flex">
      <aside className="border-b border-ink-700 bg-ink-900 md:fixed md:inset-y-0 md:w-60 md:border-b-0 md:border-r">
        <div className="flex items-center gap-2 px-5 py-4 text-white">
          <LockIcon className="h-6 w-6 text-lock-400" />
          <span className="text-lg font-bold tracking-wide">CASELOCK</span>
        </div>
        <nav className="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-col md:pb-0">
          {links.map((l) => (
            <NavLink
              key={l.to}
              to={l.to}
              className={({ isActive }) =>
                `whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition ${isActive ? 'bg-lock-500/15 text-lock-400' : 'text-slate-300 hover:bg-ink-700'}`
              }
            >
              {l.label}
            </NavLink>
          ))}
        </nav>
        <div className="hidden border-t border-ink-700 p-4 md:absolute md:inset-x-0 md:bottom-0 md:block">
          <div className="truncate text-sm font-medium text-white">{user.name}</div>
          <div className="mb-2 truncate text-xs text-slate-400">{user.email}</div>
          <div className="mb-3"><RoleBadge role={user.role} /></div>
          <button className="btn-ghost w-full" onClick={() => void logout()}>Keluar</button>
        </div>
      </aside>

      <main className="flex-1 md:ml-60">
        <div className="flex items-center justify-between border-b border-ink-700 px-4 py-2 md:hidden">
          <span className="truncate text-sm text-slate-300">{user.name}</span>
          <button className="btn-ghost !py-1" onClick={() => void logout()}>Keluar</button>
        </div>
        <div className="mx-auto max-w-6xl p-4 sm:p-8">{children}</div>
      </main>
    </div>
  )
}
