/**
 * Row shape for chat user table payloads.
 * Runtime-only fields such as onlineSessionCount are table projection fields, not generic user entity fields.
 */
export type ChatUserTableRow = {
  id: number
  name: string
  lastActivity: string | null
  onlineSessionCount?: number
  presence?: string
}
