import { HilosDaemonWorkersPage } from '@hilos/react/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Workers page with the ecommerce-shop connection and scope. */
export default function Workers() {
  return (
    <HilosDaemonWorkersPage context={hilosDaemonContext} showLogLinks={false} />
  )
}
