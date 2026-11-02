// The public /status page. The framework owns the page: it reads the published
// status files even before a handshake and owns the subscription window; the
// project binds its connection and action lifecycle, with no page scope.
import { HilosStatusPage } from '@hilos/react/status'

import { actions, connection } from '../../bootstrap/connection.js'

export default function Status() {
  return <HilosStatusPage connection={connection} actions={actions} />
}
