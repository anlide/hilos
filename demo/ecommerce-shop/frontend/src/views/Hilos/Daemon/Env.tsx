import { HilosDaemonEnvPage } from '@hilos/react'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's environment page with the ecommerce-shop context. */
export default function Env() {
  return <HilosDaemonEnvPage context={hilosDaemonContext} />
}
