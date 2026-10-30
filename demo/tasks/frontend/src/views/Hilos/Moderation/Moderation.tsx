import { HilosModerationPage } from '@hilos/react/admin/moderation'

import { hilosModerationContext } from './hilosModerationContext.js'

export default function Moderation() {
  return <HilosModerationPage context={hilosModerationContext} />
}
