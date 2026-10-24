import { HilosDaemonNodePage } from '@hilos/react/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework’s node Overview with the project connection and scope. */
export default function Node() {
  return (
    <HilosDaemonNodePage context={hilosDaemonContext} showLogLinks={false} />
  )
}
