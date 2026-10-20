import { HilosDaemonAgentsPage } from '@hilos/react'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Agents page with the ecommerce-shop context. */
export default function Agents() {
  return (
    <HilosDaemonAgentsPage context={hilosDaemonContext} showLogLinks={false} />
  )
}
