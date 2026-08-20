// The tasks main page (PAGE_MAIN). For now it shows the identity line of the
// session; the real tasks views land here later. Rendered by HilosView when the
// navigator's route is the main page.
//
// Two branches since HIL-610, because a visitor is no longer a user: with an
// account it shows the account's name from the session scope, without one the
// guest name this demo assigned. The `self-user` marker carries whichever name
// is on screen, so a test can read the line without knowing which branch it is.
import { useSignal } from '@hilos/react'

import { currentGuestName } from '../../bootstrap/guest'
import { currentUserId, currentUserName } from '../../bootstrap/session'

export default function Main() {
  const selfName = useSignal(currentUserName)
  const selfId = useSignal(currentUserId)
  const guestName = useSignal(currentGuestName)
  return (
    <>
      <h1 className="visually-hidden">Tasks</h1>
      <p>
        {selfId === null ? 'Browsing as ' : 'Signed in as '}
        <span data-id="self-user">{selfId === null ? guestName : selfName}</span>
        <span data-id="self-user-id" hidden>
          {selfId}
        </span>
      </p>
    </>
  )
}
