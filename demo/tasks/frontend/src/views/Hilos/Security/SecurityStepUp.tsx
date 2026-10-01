// The Hilos page of operations that ask for confirmation
// (HilosPages.SECURITY_STEP_UP, HIL-1204), a child of two-factor: a thin project
// binding of the framework HilosSecurityStepUpPage to this app's two-factor
// context. The table, the row view-model and the switch are the framework's; the
// project supplies only the context and registers the table on its backend.
// Bootstrap classes only (styling-rules.md).
import { HilosSecurityStepUpPage } from '@hilos/react'

import { hilosTwoFactorContext } from './hilosTwoFactorContext'

export default function SecurityStepUp() {
  return <HilosSecurityStepUpPage context={hilosTwoFactorContext} />
}
