import { HilosDaemonCronPage } from '@hilos/react'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Mount the framework's Cron page with the ecommerce-shop connection and scope. */
export default function Cron() {
  return <HilosDaemonCronPage context={hilosDaemonContext} />
}
