import { HilosCommunicationsChannelFramePage } from '@hilos/react/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

export default function Frame() {
  return (
    <HilosCommunicationsChannelFramePage context={hilosCommunicationsContext} />
  )
}
