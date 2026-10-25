import { HilosCommunicationsChannelConfigurationPage } from '@hilos/react/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

export default function Configuration() {
  return (
    <HilosCommunicationsChannelConfigurationPage
      context={hilosCommunicationsContext}
    />
  )
}
