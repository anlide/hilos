import { type HilosDataExportContext } from '@hilos/core/base'
import { HilosProfileDataPage } from '@hilos/react/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

const context: HilosDataExportContext = { connection, scopes, actions }

export default function ProfileData() {
  return <HilosProfileDataPage context={context} />
}
