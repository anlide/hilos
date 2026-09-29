// The public Privacy page (HilosPages.PRIVACY). A framework-declared static
// page; this project supplies the content and nothing else. The frame, the
// heading and the erase block under the prose are the framework's
// (HilosPrivacyPage), and this demo declares no browser value of its own, so it
// hands in no `values`. See views/About/About.tsx.
import { HilosPrivacyPage } from '@hilos/react'

import { actions, connection } from '../../bootstrap/connection.js'

export default function Privacy() {
  return (
    <HilosPrivacyPage connection={connection} actions={actions}>
      <p>
        This demo stores what an account and its sign-in need: your email
        address, your password in hashed form, the display name taken from the
        address, the terms you accepted, your browser sessions, and — for a
        while — the network address of repeated failed attempts.
      </p>
      <p className="mb-0">
        No analytics or third-party trackers are used, and demo data may be
        reset at any time.
      </p>
    </HilosPrivacyPage>
  )
}
