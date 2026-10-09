// The profile sign-in methods page (HilosPages.PROFILE_SIGN_IN, /profile/sign-in,
// HIL-1138): a thin project binding of the framework HilosProfileSignInPage to
// this app's auth context. The section and its live copy are the framework's.
// Bootstrap classes only (styling-rules.md).
import { HilosProfileSignInPage } from '@hilos/react'

import { hilosAuthContext } from '../../auth/hilosAuthContext.js'

export default function ProfileSignIn() {
  return <HilosProfileSignInPage context={hilosAuthContext} />
}
