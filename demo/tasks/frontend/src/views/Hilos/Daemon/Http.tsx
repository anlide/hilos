import { HilosDaemonHttpServerPage } from '@hilos/react'
import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's HTTP page with the tasks context. */
export default function Http() {
  return <HilosDaemonHttpServerPage context={hilosDaemonContext} />
}
