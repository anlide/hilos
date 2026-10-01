// The public Terms page (HilosPages.TERMS). The page is the framework's
// (HilosTermsPage): its body is the text of the Terms revision in force, read
// from the project's legal catalog (backend/Legal/), with the reader's standing
// and the revision history. This project supplies only the introduction above
// the text.
import { HilosTermsPage } from '@hilos/react'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

/** One context for the page's lifetime: the page keys its store on it. */
const context = { connection, scopes, actions }

export default function Terms() {
  return (
    <HilosTermsPage context={context}>
      <p>
        This is a demonstration application provided for evaluation purposes
        only, without warranty of any kind.
      </p>
    </HilosTermsPage>
  )
}
