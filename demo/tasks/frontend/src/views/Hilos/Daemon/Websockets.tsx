import { HilosDaemonWebsocketsPage } from '@hilos/react/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework’s node WebSocket count with the project connection and scope. */
export default function Websockets() {
  return <HilosDaemonWebsocketsPage context={hilosDaemonContext} />
}
