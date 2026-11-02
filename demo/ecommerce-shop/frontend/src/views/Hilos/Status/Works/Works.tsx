// The framework owns the Status Scheduled maintenance tab, its table and windows.
import { HilosStatusWorksPage } from '@hilos/react/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

export default function Works() {
  return <HilosStatusWorksPage context={hilosStatusContext} />
}
