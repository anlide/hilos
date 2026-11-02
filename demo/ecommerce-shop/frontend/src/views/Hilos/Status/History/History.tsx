// The framework owns the Status History tab and its table.
import { HilosStatusHistoryPage } from '@hilos/react/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

export default function History() {
  return <HilosStatusHistoryPage context={hilosStatusContext} />
}
