import { HilosCommunicationsDeliveriesPage } from '@hilos/react/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

export default function Deliveries() {
  return (
    <HilosCommunicationsDeliveriesPage context={hilosCommunicationsContext} />
  )
}
