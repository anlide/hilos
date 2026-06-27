// The todo main page (PAGE_MAIN). For now it shows the session's current user
// resolved from the session scope; the real todo views land here later.
// Rendered by HilosView when the navigator's route is the main page.
import { useSignal } from '@hilos/react'

import { currentUserName } from '../../bootstrap/session'

export default function Main() {
  const selfName = useSignal(currentUserName)
  return (
    <>
      <h1 className="visually-hidden">Tasks</h1>
      <p>
        Signed in as <span data-id="self-user">{selfName}</span>
      </p>
    </>
  )
}
