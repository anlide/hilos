// Root view. Besides the title it shows the live Connection-machine state
// through the React adapter — the transport slice of the conformance demo
// (docs/agents/frontend/multiframework-core.md). Real views land on top of
// this from step 7, tracking each core capability as it lands.
import { useConnectionState } from '@hilos/react'

import { connection } from './connection'

export default function App() {
  const connectionState = useConnectionState(connection)
  return (
    <main data-id="app-root">
      Hilos simple-todo (React)
      <span data-id="conn-state">{connectionState}</span>
    </main>
  )
}
