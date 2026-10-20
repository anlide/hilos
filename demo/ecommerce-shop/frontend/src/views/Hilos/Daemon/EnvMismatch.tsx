import { HilosDaemonEnvMismatchPage } from '@hilos/react'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's environment comparison with the ecommerce-shop context. */
export default function EnvMismatch() {
  return <HilosDaemonEnvMismatchPage context={hilosDaemonContext} />
}
