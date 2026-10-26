import { HilosDaemonEnvOrphansPage } from '@hilos/react/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's environment orphans page with the ecommerce-shop context. */
export default function EnvOrphans() {
  return (
    <HilosDaemonEnvOrphansPage
      context={hilosDaemonContext}
      showLogLinks={false}
    />
  )
}
