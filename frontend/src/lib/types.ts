export type Role = 'admin' | 'investigator' | 'viewer'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  is_active: boolean
  passkeys_count: number
}

export interface Meta {
  categories: Record<string, string>
  statuses: string[]
  max_file_mb: number
}

export interface CaseSummary {
  id: number
  case_number: string
  title: string
  category: string
  status: string
  owner: string | null
  files_count: number | null
  messages_count: number | null
  created_at: string
}

export interface Member {
  id: number
  name: string
  email: string
  role: 'owner' | 'investigator' | 'viewer'
}

export interface CaseDetail {
  id: number
  case_number: string
  title: string
  category: string
  status: string
  description: string | null
  reporter: string | null
  owner: { id: number; name: string }
  created_at: string
  members: Member[]
  me: { can_manage: boolean; can_contribute: boolean }
}

export interface FileItem {
  id: string
  name: string
  mime_type: string
  size_bytes: number
  sha256: string
  uploaded_by: string | null
  created_at: string
  can_decrypt: boolean
}

export interface MessageItem {
  id: number
  sender: string | null
  sender_id: number
  created_at: string
}

export interface AuditRow {
  seq: number
  user: string | null
  action: string
  subject_type: string | null
  subject_id: string | null
  case_id: number | null
  details: Record<string, unknown> | null
  prev_hash: string
  hash: string
  logged_at: string
}

export interface Passkey {
  id: number
  label: string
  created_at: string
  last_used_at: string | null
}

export type StepUpAuth =
  | { method: 'password'; password: string }
  | { method: 'passkey'; assertion: unknown }
