// The framework owns the Status Subscribers tab, its table and removal window.
import { HilosStatusSubscribersPage } from '@hilos/react/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

export default function Subscribers() {
  return <HilosStatusSubscribersPage context={hilosStatusContext} />
}
