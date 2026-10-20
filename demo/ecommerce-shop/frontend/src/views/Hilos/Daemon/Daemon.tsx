import { HilosDaemonPage } from '@hilos/react'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Daemon page with the ecommerce-shop connection and scope. */
export default function Daemon() {
  return <HilosDaemonPage context={hilosDaemonContext} showLogLinks={false} />
}
