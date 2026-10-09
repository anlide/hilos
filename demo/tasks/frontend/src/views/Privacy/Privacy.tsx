// The public Privacy page (HilosPages.PRIVACY). A framework-declared static
// page; this project supplies the content and nothing else. The frame, the
// heading and the erase block under the prose are the framework's
// (HilosPrivacyPage), and this demo declares no browser value of its own, so it
// hands in no `values`. See views/About/About.tsx.
import { HilosPrivacyPage } from '@hilos/react'

import { actions, connection } from '../../bootstrap/connection'

export default function Privacy() {
  return (
    <HilosPrivacyPage connection={connection} actions={actions}>
      <p>
        This demo stores only the data needed to show its real-time features:
        your chosen display name and the tasks you create.
      </p>
      <p>
        This demo records analytics: times, network addresses, browser and
        language details, visited pages and route parameters, and names of
        actions and internal signals. It does not store action contents or
        arbitrary API request bodies in analytics.
      </p>
      <p className="mb-0">
        After account deletion, your numeric account number, analytics events,
        and network addresses remain. There is currently no automatic deletion
        period for raw analytics. This demo uses no third-party trackers, and
        demo data may be reset at any time.
      </p>
    </HilosPrivacyPage>
  )
}
