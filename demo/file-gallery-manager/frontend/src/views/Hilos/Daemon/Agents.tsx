import { HilosDaemonAgentsPage } from '@hilos/react/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Agents page with the project context. */
export default function Agents() {
  return <HilosDaemonAgentsPage context={hilosDaemonContext} />
}
