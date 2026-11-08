import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './context/AuthContext'
import Layout from './components/Layout'
import Login from './pages/Login'
import Cases from './pages/Cases'
import CaseDetailPage from './pages/CaseDetail'
import Stego from './pages/Stego'
import Security from './pages/Security'
import Users from './pages/Users'
import AuditPage from './pages/Audit'

export default function App() {
  const { user, loading } = useAuth()

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-slate-400">Memuat…</div>
  }
  if (!user) {
    return (
      <Routes>
        <Route path="*" element={<Login />} />
      </Routes>
    )
  }

  return (
    <Layout>
      <Routes>
        <Route path="/" element={<Navigate to="/cases" replace />} />
        <Route path="/cases" element={<Cases />} />
        <Route path="/cases/:id" element={<CaseDetailPage />} />
        <Route path="/stego" element={<Stego />} />
        <Route path="/security" element={<Security />} />
        {user.role === 'admin' && <Route path="/users" element={<Users />} />}
        {user.role === 'admin' && <Route path="/audit" element={<AuditPage />} />}
        <Route path="*" element={<Navigate to="/cases" replace />} />
      </Routes>
    </Layout>
  )
}
