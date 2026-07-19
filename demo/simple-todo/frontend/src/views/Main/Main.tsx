// The todo main page (PAGE_MAIN). For now it shows the session's current user
// resolved from the session scope; the real todo views land here later.
// Rendered by HilosView when the navigator's route is the main page.
import { useSignal } from '@hilos/react'

import { currentUserId, currentUserName } from '../../bootstrap/session'

export default function Main() {
  const selfName = useSignal(currentUserName)
  const selfId = useSignal(currentUserId)
  return (
    <>
      <h1 className="visually-hidden">Tasks</h1>
      <p>
        Signed in as <span data-id="self-user">{selfName}</span>
        <span data-id="self-user-id" hidden>
          {selfId}
        </span>
      </p>
    </>
  )
}
