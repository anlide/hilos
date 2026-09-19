// The Hilos sign-in methods page (HilosPages.SECURITY_SIGN_IN_METHODS, HIL-427): a
// thin project binding of the framework HilosSecuritySignInMethodsPage to this
// app's context. The table, the row view-model and the switch are the framework's;
// the project supplies only the context and declares its methods on its backend.
// Bootstrap classes only (styling-rules.md).
import { HilosSecuritySignInMethodsPage } from '@hilos/react'

import { hilosSignInMethodsContext } from './hilosSignInMethodsContext'

export default function SecuritySignInMethods() {
  return <HilosSecuritySignInMethodsPage context={hilosSignInMethodsContext} />
}
