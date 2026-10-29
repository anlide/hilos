import { HilosLegalHistoryPage } from '@hilos/react/base'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { router } from '../../pages/routes.js'

const context = { connection, scopes, actions }
const navigation = hilosPublicLegalNavigation(
  HilosPages.PRIVACY_HISTORY,
  (page, params) => router.path(page, params),
)

export default function PrivacyHistory() {
  return (
    <HilosLegalHistoryPage
      context={context}
      document="privacy"
      navigation={navigation}
    />
  )
}
