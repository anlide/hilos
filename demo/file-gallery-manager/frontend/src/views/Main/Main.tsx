// The home page (PAGE_MAIN). It shows nothing: the demo is an empty carrier of
// the framework's sections. Rendered by HilosView when the navigator's route is
// the main page.
//
// The page still owns its one heading, kept for assistive technology, and for
// a signed-in session the hidden marks of who is looking; a visitor is not a
// user, so a guest gets no marks rather than empty ones.
import { useSignal } from '@hilos/react/base'

import { currentUserId, currentUserName } from '../../bootstrap/session.js'

export default function Main() {
  const selfName = useSignal(currentUserName)
  const selfId = useSignal(currentUserId)
  // What decides the branch is whether the session names a user, never whether
  // a name string came out empty: the id and the name are read off one
  // session-scope ref, so an absent ref gives a null id and an empty name together.
  return (
    <>
      <h1 className="visually-hidden">File Gallery Manager</h1>
      {selfId !== null && (
        <>
          <span className="visually-hidden" data-id="self-user">
            {selfName}
          </span>
          <span data-id="self-user-id" hidden>
            {selfId}
          </span>
        </>
      )}
    </>
  )
}
