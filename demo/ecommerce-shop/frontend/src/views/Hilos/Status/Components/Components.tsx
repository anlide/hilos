// The framework owns the Status Components tab and its read-only table.
import { HilosStatusComponentsPage } from '@hilos/react/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

export default function Components() {
  return <HilosStatusComponentsPage context={hilosStatusContext} />
}
