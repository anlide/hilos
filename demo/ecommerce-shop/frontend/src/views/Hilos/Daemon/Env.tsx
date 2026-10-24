import { HilosDaemonEnvPage } from '@hilos/react/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's environment page with the ecommerce-shop context. */
export default function Env() {
  return (
    <HilosDaemonEnvPage context={hilosDaemonContext} showLogLinks={false} />
  )
}
