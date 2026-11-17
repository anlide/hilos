// The profile data page (HilosPages.PROFILE_DATA, /profile/data): a thin project
// binding of the framework HilosProfileDataPage to this app's connection, scopes
// and action lifecycle. The personal-copy section and its export flow are the
// framework's.
import { type HilosDataExportContext } from '@hilos/core/base'
import { HilosProfileDataPage } from '@hilos/react/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

const context: HilosDataExportContext = { connection, scopes, actions }

export default function ProfileData() {
  return <HilosProfileDataPage context={context} />
}
