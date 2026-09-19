// The Hilos OAuth provider page (HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): a
// thin project binding of the framework HilosSecurityOauthProviderPage to this
// app's context. Bootstrap classes only (styling-rules.md).
import { HilosSecurityOauthProviderPage } from '@hilos/react'

import { hilosSecurityOauthContext } from './hilosSecurityOauthContext'

export default function SecurityOauthProvider() {
  return <HilosSecurityOauthProviderPage context={hilosSecurityOauthContext} />
}
