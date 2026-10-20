import { HilosDaemonEnvMismatchPage } from '@hilos/react/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's environment comparison with the tasks context. */
export default function EnvMismatch() {
  return <HilosDaemonEnvMismatchPage context={hilosDaemonContext} />
}
