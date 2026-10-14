import { HilosDaemonPage } from '@hilos/react'
import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Daemon page with the tasks connection and scope. */
export default function Daemon() {
  return <HilosDaemonPage context={hilosDaemonContext} />
}
