// The framework owns the Status Overview and its incident dialogs.
import { HilosStatusPage } from '@hilos/react/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

export default function Status() {
  return <HilosStatusPage context={hilosStatusContext} />
}
