// The public Terms page (HilosPages.TERMS). The page is the framework's
// (HilosTermsPage): its body is the text of the Terms revision in force, read
// from the project's legal catalog (backend/Legal/), with the reader's standing
// and comparison. Revision history has its own page. This project supplies
// only the introduction above the text.
import { HilosTermsPage } from '@hilos/react/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { router } from '../../pages/routes.js'

/** One context for the page's lifetime: the page keys its store on it. */
const context = { connection, scopes, actions }
const navigation = hilosPublicLegalNavigation(
  HilosPages.TERMS,
  (page, params) => router.path(page, params),
)

export default function Terms() {
  return (
    <HilosTermsPage context={context} navigation={navigation}>
      <p>
        This is a demonstration application provided for evaluation purposes
        only, without warranty of any kind.
      </p>
    </HilosTermsPage>
  )
}
