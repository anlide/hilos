import { HilosDaemonPlacementPage } from '@hilos/react/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Agent placement page with the ecommerce-shop connection and scope. */
export default function Placement() {
  return <HilosDaemonPlacementPage context={hilosDaemonContext} />
}
