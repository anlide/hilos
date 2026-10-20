// The profile security page (HilosPages.PROFILE_SECURITY, /profile/security,
// HIL-494): a thin project binding of the framework HilosProfileSecurityPage to
// this app's connection, scopes and action lifecycle. The section, its modals and
// its live copy are the framework's. Bootstrap classes only (styling-rules.md).
import { type HilosSecondFactorContext } from '@hilos/core/base'
import { HilosProfileSecurityPage } from '@hilos/react/base'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

const context: HilosSecondFactorContext = { connection, scopes, actions }

export default function ProfileSecurity() {
  return <HilosProfileSecurityPage context={context} />
}
