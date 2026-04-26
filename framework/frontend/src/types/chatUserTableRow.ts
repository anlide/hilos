/**
 * Row shape for the chat `users` table from WebSocket table payloads (toFrontend serialization).
 * Matches backend View\User::toArray(toFrontend: true) — no sessionToken.
 */
export type ChatUserTableRow = {
  id: number
  name: string
  lastActivity: string | null
  onlineSessionCount?: number
  presence?: string
}
