// The profile security page (HilosPages.PROFILE_SECURITY, /profile/security,
// HIL-494): a thin project binding of the framework HilosProfileSecurityPage to
// this app's connection, scopes and action lifecycle. The section, its modals and
// its live copy are the framework's. The account deletion's danger zone sits at
// its bottom (HIL-302): this app's profile has no other page to carry it.
// Bootstrap classes only (styling-rules.md).
import { type HilosSecondFactorContext } from '@hilos/core'
import { HilosAccountDeletion, HilosProfileSecurityPage } from '@hilos/react'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

const context: HilosSecondFactorContext = { connection, scopes, actions }

export default function ProfileSecurity() {
  return (
    <>
      <HilosProfileSecurityPage context={context} />
      <HilosAccountDeletion context={context} />
    </>
  )
}
