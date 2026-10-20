import { HilosDaemonHttpServerPage } from '@hilos/react/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's HTTP page with the ecommerce-shop context. */
export default function Http() {
  return <HilosDaemonHttpServerPage context={hilosDaemonContext} />
}
