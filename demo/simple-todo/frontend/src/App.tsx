// Root view. Besides the title it shows the live Connection-machine state
// through the React adapter — the transport slice of the conformance demo
// (docs/agents/frontend/multiframework-core.md) — and the current user
// resolved from the session scope. Real views land on top of this from
// step 7, tracking each core capability as it lands.
import { useConnectionState, useSignal } from '@hilos/react'

import { connection } from './connection'
import { currentUserName } from './session'

export default function App() {
  const connectionState = useConnectionState(connection)
  const selfName = useSignal(currentUserName)
  return (
    <main data-id="app-root">
      Hilos simple-todo (React)
      <span data-id="conn-state">{connectionState}</span>
      <span data-id="self-user">{selfName}</span>
    </main>
  )
}
