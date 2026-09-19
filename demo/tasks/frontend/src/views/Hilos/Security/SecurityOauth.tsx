// The Hilos OAuth providers page (HilosPages.SECURITY_OAUTH, HIL-286): a thin
// project binding of the framework HilosSecurityOauthPage to this app's context.
// The tables, the row view-models, and the round-trips are the framework's; the
// project supplies only the context and declares its providers on its backend.
// Bootstrap classes only (styling-rules.md).
import { HilosSecurityOauthPage } from '@hilos/react'

import { hilosSecurityOauthContext } from './hilosSecurityOauthContext'

export default function SecurityOauth() {
  return <HilosSecurityOauthPage context={hilosSecurityOauthContext} />
}
