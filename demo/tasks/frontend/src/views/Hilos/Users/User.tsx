// The Hilos user detail page (HilosPages.USER): a thin project binding of the
// framework HilosUserPage to this app's context. The profile selector, the inline
// rename, and the success/fail handling are the framework's; the project supplies
// only the shared HilosUsersContext (its scopes, connection, and user collection).
// Bootstrap classes only (styling-rules.md).
import { HilosUserPage } from '@hilos/react'

import { hilosUsersContext } from './hilosUsersContext'

export default function User() {
  return <HilosUserPage context={hilosUsersContext} />
}
